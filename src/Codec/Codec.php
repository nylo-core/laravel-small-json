<?php

declare(strict_types=1);

namespace SmallJson\Codec;

use JsonException;
use SmallJson\Exceptions\DecodeException;
use SmallJson\Exceptions\EncodeException;
use stdClass;

/**
 * Builds and reads SmallJson v1 envelopes. Framework-free:
 * usable outside Laravel for queues, storage, websockets, or other clients.
 */
final class Codec
{
    public const VERSION = 1;
    public const MODE_PACKED = 'p';
    public const MODE_DEFLATE = 'z';
    public const MODES = 'pz';
    public const CONTENT_TYPE = 'application/vnd.smalljson+json';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly int $deflateLevel = 6,
        private readonly int $deflateMinBytes = 1024,
    ) {
    }

    /**
     * Encode a value that is already in json_decode() form (stdClass objects,
     * list arrays, scalars, null) into the smallest envelope the given modes
     * allow. Mode "p" is always required.
     */
    public function encode(mixed $value, string $modes = self::MODES): EncodedPayload
    {
        if (! str_contains($modes, self::MODE_PACKED)) {
            throw new EncodeException('SmallJson requires mode "p"; got "'.$modes.'".');
        }

        [$keys, $body] = (new Packer)->pack($value);

        $envelope = $this->toJson(['_sj' => self::VERSION, 'm' => self::MODE_PACKED, 'k' => $keys, 'b' => $body]);

        if (str_contains($modes, self::MODE_DEFLATE) && strlen($envelope) >= $this->deflateMinBytes) {
            $deflated = gzdeflate($this->toJson(['k' => $keys, 'b' => $body]), $this->deflateLevel);

            if ($deflated !== false) {
                $candidate = $this->toJson(['_sj' => self::VERSION, 'm' => self::MODE_DEFLATE, 'd' => base64_encode($deflated)]);

                if (strlen($candidate) < strlen($envelope)) {
                    return new EncodedPayload(self::MODE_DEFLATE, $candidate);
                }
            }
        }

        return new EncodedPayload(self::MODE_PACKED, $envelope);
    }

    /**
     * Decode an envelope back into the original data. Accepts the raw body
     * string or an already-parsed envelope (stdClass or associative array).
     *
     * @param  bool  $assoc  Return objects as associative arrays instead of stdClass.
     */
    public function decode(string|stdClass|array $payload, bool $assoc = false): mixed
    {
        $envelope = is_string($payload) ? $this->parse($payload) : $payload;

        if ($this->field($envelope, '_sj') !== self::VERSION) {
            throw new DecodeException('Payload is not a SmallJson v'.self::VERSION.' envelope.');
        }

        $mode = $this->field($envelope, 'm');

        if ($mode === self::MODE_DEFLATE) {
            $document = $this->parse($this->inflate($envelope));
        } elseif ($mode === self::MODE_PACKED) {
            $document = $envelope;
        } else {
            throw new DecodeException('Unsupported SmallJson mode.');
        }

        $keys = $this->field($document, 'k');

        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new DecodeException('The key table must be an array of strings.');
        }

        if (! $this->has($document, 'b')) {
            throw new DecodeException('The envelope is missing its body.');
        }

        return (new Packer)->unpack($this->field($document, 'b'), $keys, $assoc);
    }

    /**
     * Whether a parsed body looks like a SmallJson envelope (cheap sniff; the
     * decoder still validates strictly).
     */
    public static function looksLikeEnvelope(mixed $parsed): bool
    {
        if ($parsed instanceof stdClass) {
            return ($parsed->_sj ?? null) === self::VERSION;
        }

        return is_array($parsed) && ($parsed['_sj'] ?? null) === self::VERSION;
    }

    private function inflate(stdClass|array $envelope): string
    {
        $data = $this->field($envelope, 'd');

        if (! is_string($data)) {
            throw new DecodeException('The deflate envelope is missing its "d" payload.');
        }

        $binary = base64_decode($data, true);

        if ($binary === false) {
            throw new DecodeException('The deflate payload is not valid base64.');
        }

        $json = @gzinflate($binary);

        if ($json === false) {
            throw new DecodeException('The deflate payload could not be inflated.');
        }

        return $json;
    }

    private function parse(string $json): stdClass
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DecodeException('Payload is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! $decoded instanceof stdClass) {
            throw new DecodeException('Payload is not a SmallJson envelope.');
        }

        return $decoded;
    }

    private function field(stdClass|array $envelope, string $key): mixed
    {
        return $envelope instanceof stdClass ? ($envelope->{$key} ?? null) : ($envelope[$key] ?? null);
    }

    private function has(stdClass|array $envelope, string $key): bool
    {
        return $envelope instanceof stdClass ? property_exists($envelope, $key) : array_key_exists($key, $envelope);
    }

    private function toJson(mixed $value): string
    {
        try {
            return json_encode($value, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EncodeException('Could not serialise the SmallJson envelope: '.$e->getMessage(), previous: $e);
        }
    }
}
