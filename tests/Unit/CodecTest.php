<?php

declare(strict_types=1);

namespace SmallJson\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmallJson\Codec\Codec;
use SmallJson\Exceptions\DecodeException;
use SmallJson\Exceptions\EncodeException;

class CodecTest extends TestCase
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function test_packed_envelopes_have_the_documented_shape(): void
    {
        $payload = (new Codec)->encode(json_decode('{"a":1}'), 'p');

        $this->assertSame('p', $payload->mode);
        $this->assertSame('{"_sj":1,"m":"p","k":["a"],"b":[0,0,1]}', $payload->body);
    }

    public function test_deflate_engages_on_large_payloads_and_wins(): void
    {
        $value = json_decode(json_encode($this->bigCollection()));
        $codec = new Codec(deflateLevel: 6, deflateMinBytes: 0);

        $packedOnly = $codec->encode($value, 'p');
        $deflated = $codec->encode($value, 'pz');

        $this->assertSame('p', $packedOnly->mode);
        $this->assertSame('z', $deflated->mode);
        $this->assertLessThan($packedOnly->bytes(), $deflated->bytes());

        $this->assertSame(
            json_encode($value, self::FLAGS),
            json_encode($codec->decode($deflated->body), self::FLAGS),
        );
    }

    public function test_deflate_is_skipped_below_the_size_threshold(): void
    {
        $codec = new Codec(deflateMinBytes: 1024 * 1024);

        $payload = $codec->encode(json_decode(json_encode($this->bigCollection())), 'pz');

        $this->assertSame('p', $payload->mode);
    }

    public function test_deflate_is_skipped_when_the_client_does_not_accept_it(): void
    {
        $codec = new Codec(deflateMinBytes: 0);

        $payload = $codec->encode(json_decode(json_encode($this->bigCollection())), 'p');

        $this->assertSame('p', $payload->mode);
    }

    public function test_tiny_payloads_never_pick_deflate(): void
    {
        // base64 + envelope overhead always loses on tiny bodies, even with min bytes 0.
        $payload = (new Codec(deflateMinBytes: 0))->encode(json_decode('{"a":1}'), 'pz');

        $this->assertSame('p', $payload->mode);
    }

    public function test_encode_requires_the_packed_mode(): void
    {
        $this->expectException(EncodeException::class);

        (new Codec)->encode(json_decode('{"a":1}'), 'z');
    }

    public function test_encode_rejects_values_json_cannot_represent(): void
    {
        $this->expectException(EncodeException::class);

        (new Codec)->encode(INF);
    }

    public function test_encode_rejects_invalid_utf8_strings(): void
    {
        $this->expectException(EncodeException::class);

        (new Codec)->encode("\xB1\x31");
    }

    public function test_decode_accepts_pre_parsed_envelopes(): void
    {
        $codec = new Codec;
        $body = $codec->encode(json_decode('{"a":1,"b":[1,2]}'))->body;

        $fromString = $codec->decode($body);
        $fromObject = $codec->decode(json_decode($body));
        $fromArray = $codec->decode(json_decode($body, true));

        $expected = json_encode($fromString, self::FLAGS);
        $this->assertSame($expected, json_encode($fromObject, self::FLAGS));
        $this->assertSame($expected, json_encode($fromArray, self::FLAGS));
    }

    public function test_assoc_decoding_returns_arrays(): void
    {
        $codec = new Codec;
        $body = $codec->encode(json_decode('{"user":{"id":1}}'))->body;

        $this->assertSame(['user' => ['id' => 1]], $codec->decode($body, true));
    }

    public function test_envelope_sniffing(): void
    {
        $this->assertTrue(Codec::looksLikeEnvelope(json_decode('{"_sj":1,"m":"p","k":[],"b":null}')));
        $this->assertTrue(Codec::looksLikeEnvelope(['_sj' => 1, 'm' => 'p']));
        $this->assertFalse(Codec::looksLikeEnvelope(json_decode('{"data":[]}')));
        $this->assertFalse(Codec::looksLikeEnvelope('nope'));
        $this->assertFalse(Codec::looksLikeEnvelope(null));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'not json' => ['nope{'],
            'not an envelope' => ['[1,2,3]'],
            'wrong version' => ['{"_sj":2,"m":"p","k":[],"b":null}'],
            'missing version' => ['{"m":"p","k":[],"b":null}'],
            'unknown mode' => ['{"_sj":1,"m":"x","k":[],"b":null}'],
            'missing mode' => ['{"_sj":1,"k":[],"b":null}'],
            'missing body' => ['{"_sj":1,"m":"p","k":[]}'],
            'key table with non-strings' => ['{"_sj":1,"m":"p","k":[1],"b":null}'],
            'key table not an array' => ['{"_sj":1,"m":"p","k":"x","b":null}'],
            'unknown container tag' => ['{"_sj":1,"m":"p","k":[],"b":[9]}'],
            'float container tag' => ['{"_sj":1,"m":"p","k":[],"b":[0.0]}'],
            'empty container' => ['{"_sj":1,"m":"p","k":[],"b":[]}'],
            'incomplete object pairs' => ['{"_sj":1,"m":"p","k":["a"],"b":[0,0]}'],
            'key ref out of range' => ['{"_sj":1,"m":"p","k":["a"],"b":[0,1,true]}'],
            'key ref not an int' => ['{"_sj":1,"m":"p","k":["a"],"b":[0,"a",true]}'],
            'raw object inside body' => ['{"_sj":1,"m":"p","k":[],"b":{"a":1}}'],
            'table without refs' => ['{"_sj":1,"m":"p","k":[],"b":[2]}'],
            'table refs not an array' => ['{"_sj":1,"m":"p","k":["a"],"b":[2,0,[1]]}'],
            'row width mismatch' => ['{"_sj":1,"m":"p","k":["a","b"],"b":[2,[0,1],[1]]}'],
            'row not an array' => ['{"_sj":1,"m":"p","k":["a"],"b":[2,[0],5]}'],
            'deflate with bad base64' => ['{"_sj":1,"m":"z","d":"!!!"}'],
            'deflate with bad stream' => ['{"_sj":1,"m":"z","d":"aGVsbG8="}'],
            'deflate without payload' => ['{"_sj":1,"m":"z"}'],
            'deflate with non-string payload' => ['{"_sj":1,"m":"z","d":5}'],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payloads_are_rejected(string $payload): void
    {
        $this->expectException(DecodeException::class);

        (new Codec)->decode($payload);
    }

    private function bigCollection(): array
    {
        $rows = [];

        for ($i = 0; $i < 100; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => 'User '.$i,
                'email' => 'user'.$i.'@example.com',
                'active' => $i % 2 === 0,
                'balance' => $i * 3.25,
            ];
        }

        return $rows;
    }
}
