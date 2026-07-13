<?php

declare(strict_types=1);

namespace SmallJson\Codec;

use SmallJson\Exceptions\DecodeException;
use SmallJson\Exceptions\EncodeException;
use stdClass;

/**
 * The structural transform of the SmallJson wire format.
 *
 * Values are expected in json_decode() form: objects as stdClass, arrays as
 * list arrays, plus scalars and null. Normalise arbitrary input with
 * json_decode(json_encode($data)) before packing.
 */
final class Packer
{
    public const TAG_OBJECT = 0;
    public const TAG_LIST = 1;
    public const TAG_TABLE = 2;

    /**
     * @return array{0: list<string>, 1: mixed} The key table and the packed body.
     */
    public function pack(mixed $value): array
    {
        $keys = [];
        $indexByKey = [];
        $body = $this->packNode($value, $keys, $indexByKey);

        return [$keys, $body];
    }

    /**
     * @param  list<string>  $keys
     * @param  bool  $assoc  Return objects as associative arrays instead of stdClass.
     */
    public function unpack(mixed $body, array $keys, bool $assoc = false): mixed
    {
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new DecodeException('The key table may only contain strings.');
            }
        }

        return $this->unpackNode($body, array_values($keys), $assoc);
    }

    private function packNode(mixed $value, array &$keys, array &$indexByKey): mixed
    {
        if ($value instanceof stdClass) {
            $node = [self::TAG_OBJECT];

            foreach (get_object_vars($value) as $key => $item) {
                $node[] = $this->keyRef((string) $key, $keys, $indexByKey);
                $node[] = $this->packNode($item, $keys, $indexByKey);
            }

            return $node;
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                throw new EncodeException('Associative arrays cannot be packed directly; normalise input with json_decode(json_encode($data)) first.');
            }

            if (($tableKeys = $this->tableKeys($value)) !== null) {
                $refs = [];

                foreach ($tableKeys as $key) {
                    $refs[] = $this->keyRef($key, $keys, $indexByKey);
                }

                $node = [self::TAG_TABLE, $refs];

                foreach ($value as $row) {
                    $packedRow = [];

                    foreach (array_values(get_object_vars($row)) as $item) {
                        $packedRow[] = $this->packNode($item, $keys, $indexByKey);
                    }

                    $node[] = $packedRow;
                }

                return $node;
            }

            $node = [self::TAG_LIST];

            foreach ($value as $item) {
                $node[] = $this->packNode($item, $keys, $indexByKey);
            }

            return $node;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new EncodeException('Cannot pack a value of type '.get_debug_type($value).'; normalise input with json_decode(json_encode($data)) first.');
    }

    /**
     * The shared key list when every element is an object with identical keys
     * in identical order (and there are at least two of them), null otherwise.
     *
     * @return list<string>|null
     */
    private function tableKeys(array $list): ?array
    {
        if (count($list) < 2) {
            return null;
        }

        $keys = null;

        foreach ($list as $item) {
            if (! $item instanceof stdClass) {
                return null;
            }

            $itemKeys = array_map(strval(...), array_keys(get_object_vars($item)));

            if ($keys === null) {
                $keys = $itemKeys;
            } elseif ($keys !== $itemKeys) {
                return null;
            }
        }

        return $keys;
    }

    private function keyRef(string $key, array &$keys, array &$indexByKey): int
    {
        $index = $indexByKey[$key] ?? null;

        if ($index === null) {
            $index = count($keys);
            $keys[] = $key;
            $indexByKey[$key] = $index;
        }

        return $index;
    }

    /**
     * @param  list<string>  $keys
     */
    private function unpackNode(mixed $node, array $keys, bool $assoc): mixed
    {
        if (! is_array($node)) {
            if ($node instanceof stdClass) {
                throw new DecodeException('Unexpected raw object inside a packed body.');
            }

            return $node;
        }

        if (! array_is_list($node)) {
            throw new DecodeException('Packed containers must be JSON arrays.');
        }

        return match ($node[0] ?? null) {
            self::TAG_OBJECT => $this->unpackObject($node, $keys, $assoc),
            self::TAG_LIST => $this->unpackList($node, $keys, $assoc),
            self::TAG_TABLE => $this->unpackTable($node, $keys, $assoc),
            default => throw new DecodeException('Invalid container tag inside a packed body.'),
        };
    }

    /**
     * @param  list<string>  $keys
     */
    private function unpackObject(array $node, array $keys, bool $assoc): stdClass|array
    {
        $count = count($node);

        if ($count % 2 === 0) {
            throw new DecodeException('A packed object must hold complete key/value pairs.');
        }

        $object = $assoc ? [] : new stdClass;

        for ($i = 1; $i < $count; $i += 2) {
            $key = $this->keyAt($node[$i], $keys);
            $value = $this->unpackNode($node[$i + 1], $keys, $assoc);

            if ($assoc) {
                $object[$key] = $value;
            } else {
                $object->{$key} = $value;
            }
        }

        return $object;
    }

    /**
     * @param  list<string>  $keys
     */
    private function unpackList(array $node, array $keys, bool $assoc): array
    {
        $list = [];
        $count = count($node);

        for ($i = 1; $i < $count; $i++) {
            $list[] = $this->unpackNode($node[$i], $keys, $assoc);
        }

        return $list;
    }

    /**
     * @param  list<string>  $keys
     */
    private function unpackTable(array $node, array $keys, bool $assoc): array
    {
        $refs = $node[1] ?? null;

        if (! is_array($refs) || ! array_is_list($refs)) {
            throw new DecodeException('A packed collection must list its keys first.');
        }

        $names = [];

        foreach ($refs as $ref) {
            $names[] = $this->keyAt($ref, $keys);
        }

        $width = count($names);
        $rows = [];
        $count = count($node);

        for ($i = 2; $i < $count; $i++) {
            $row = $node[$i];

            if (! is_array($row) || ! array_is_list($row) || count($row) !== $width) {
                throw new DecodeException('A packed collection row must match its key list.');
            }

            $object = $assoc ? [] : new stdClass;

            foreach ($row as $j => $value) {
                if ($assoc) {
                    $object[$names[$j]] = $this->unpackNode($value, $keys, $assoc);
                } else {
                    $object->{$names[$j]} = $this->unpackNode($value, $keys, $assoc);
                }
            }

            $rows[] = $object;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $keys
     */
    private function keyAt(mixed $ref, array $keys): string
    {
        if (! is_int($ref) || ! array_key_exists($ref, $keys)) {
            throw new DecodeException('Key reference does not point into the key table.');
        }

        return $keys[$ref];
    }
}
