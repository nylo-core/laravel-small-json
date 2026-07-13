<?php

declare(strict_types=1);

namespace SmallJson\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmallJson\Codec\Packer;
use SmallJson\Exceptions\DecodeException;
use SmallJson\Exceptions\EncodeException;

class PackerTest extends TestCase
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @return array<string, array{0: string}>
     */
    public static function documents(): array
    {
        return [
            'flat object' => ['{"id":7,"name":"Anthony","admin":true,"score":9.75,"bio":null}'],
            'collection' => ['[{"id":1,"name":"Ada"},{"id":2,"name":"Grace"},{"id":3,"name":"Alan"}]'],
            'nested collections' => ['{"data":[{"id":1,"tags":["a","b"],"meta":{"views":10}},{"id":2,"tags":[],"meta":{"views":0}}],"links":{"next":null}}'],
            'mixed array' => ['[1,"two",null,true,{"a":1},[2,3],{"a":2}]'],
            'empty containers' => ['{"empty":{},"list":[],"deep":{"also":{}}}'],
            'root string' => ['"hello"'],
            'root int' => ['42'],
            'root null' => ['null'],
            'root bool' => ['false'],
            'root empty object' => ['{}'],
            'root empty array' => ['[]'],
            'unicode' => ['{"città":"München","emoji":"🚀🔥","cjk":"日本語のテキスト"}'],
            'escapes' => ['{"quote":"say \"hi\"","back":"a\\\\b","nl":"line1\nline2","tab":"\tx","ctl":"\u0001\u001f"}'],
            'numeric keys' => ['{"0":"zero","1":"one","10":"ten","01":"padded"}'],
            'same keys different order' => ['[{"a":1,"b":2},{"b":3,"a":4}]'],
            'single object array' => ['[{"a":1}]'],
            'key dedupe across depths' => ['{"id":1,"child":{"id":2,"child":{"id":3}}}'],
            'big numbers' => ['{"big":9223372036854775807,"neg":-9223372036854775807,"float":1.5e300,"small":1.0e-10}'],
            'deep nesting' => ['[[[[[[[[[["deep"]]]]]]]]]]'],
            'nulls in rows' => ['[{"a":null,"b":1},{"a":2,"b":null}]'],
            'objects in rows' => ['[{"u":{"id":1},"n":1},{"u":{"id":2},"n":2}]'],
        ];
    }

    #[DataProvider('documents')]
    public function test_documents_survive_the_round_trip(string $json): void
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $packer = new Packer;

        [$keys, $body] = $packer->pack($value);
        $decoded = $packer->unpack($body, $keys);

        $this->assertSame(
            json_encode($value, self::FLAGS),
            json_encode($decoded, self::FLAGS),
        );
    }

    #[DataProvider('documents')]
    public function test_packed_bodies_are_json_safe(string $json): void
    {
        $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        [$keys, $body] = (new Packer)->pack($value);

        // The packed form itself must survive JSON transport unchanged.
        $rehydratedBody = json_decode(json_encode(['b' => $body], self::FLAGS), false)->b;
        $decoded = (new Packer)->unpack($rehydratedBody, $keys);

        $this->assertSame(
            json_encode($value, self::FLAGS),
            json_encode($decoded, self::FLAGS),
        );
    }

    public function test_uniform_collections_pack_to_tables(): void
    {
        [$keys, $body] = (new Packer)->pack(json_decode('[{"id":1,"name":"Ada"},{"id":2,"name":"Grace"}]'));

        $this->assertSame(['id', 'name'], $keys);
        $this->assertSame([2, [0, 1], [1, 'Ada'], [2, 'Grace']], $body);
    }

    public function test_key_order_mismatch_falls_back_to_a_plain_list(): void
    {
        [, $body] = (new Packer)->pack(json_decode('[{"a":1,"b":2},{"b":3,"a":4}]'));

        $this->assertSame(Packer::TAG_LIST, $body[0]);
    }

    public function test_single_element_arrays_are_not_tables(): void
    {
        [, $body] = (new Packer)->pack(json_decode('[{"a":1}]'));

        $this->assertSame(Packer::TAG_LIST, $body[0]);
    }

    public function test_keys_are_deduplicated_across_depths(): void
    {
        [$keys] = (new Packer)->pack(json_decode('{"id":1,"child":{"id":2,"child":{"id":3}}}'));

        $this->assertSame(['id', 'child'], $keys);
    }

    public function test_empty_object_and_empty_array_stay_distinct(): void
    {
        $packer = new Packer;
        [$keys, $body] = $packer->pack(json_decode('{"o":{},"a":[]}'));

        $decoded = $packer->unpack($body, $keys);

        $this->assertSame('{"o":{},"a":[]}', json_encode($decoded, self::FLAGS));
    }

    public function test_object_key_order_is_preserved(): void
    {
        $packer = new Packer;
        [$keys, $body] = $packer->pack(json_decode('{"z":1,"a":2,"m":3}'));

        $this->assertSame('{"z":1,"a":2,"m":3}', json_encode($packer->unpack($body, $keys), self::FLAGS));
    }

    public function test_assoc_unpacking_returns_arrays(): void
    {
        $packer = new Packer;
        [$keys, $body] = $packer->pack(json_decode('{"user":{"id":1}}'));

        $this->assertSame(['user' => ['id' => 1]], $packer->unpack($body, $keys, true));
    }

    public function test_packing_rejects_associative_arrays(): void
    {
        $this->expectException(EncodeException::class);

        (new Packer)->pack(['name' => 'not normalised']);
    }

    public function test_packing_rejects_unsupported_types(): void
    {
        $this->expectException(EncodeException::class);

        (new Packer)->pack([fopen('php://memory', 'r')]);
    }

    public function test_unpacking_rejects_non_string_key_tables(): void
    {
        $this->expectException(DecodeException::class);

        (new Packer)->unpack([0], [1]);
    }

    public function test_unpacking_rejects_associative_containers(): void
    {
        // Reachable when a caller hands decode() a pre-parsed assoc envelope
        // whose body holds a map where a packed (list) container belongs.
        $this->expectException(DecodeException::class);

        (new Packer)->unpack(['a' => 1], []);
    }
}
