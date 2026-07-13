<?php

declare(strict_types=1);

namespace SmallJson\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmallJson\Codec\Codec;

/**
 * Cross-implementation vectors shared with the smalljson Dart package.
 * If one of these fails, the wire format broke — bump the version instead
 * of changing the encoding silently.
 */
class VectorsTest extends TestCase
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private static ?object $fixture = null;

    private static function fixture(): object
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/vectors.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, array{0: object}>
     */
    public static function vectors(): array
    {
        $cases = [];

        foreach (self::fixture()->vectors as $vector) {
            $cases[$vector->name] = [$vector];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: object}>
     */
    public static function deflateVectors(): array
    {
        $cases = [];

        foreach (self::fixture()->deflate as $vector) {
            $cases[$vector->name] = [$vector];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_encoding_is_byte_stable(object $vector): void
    {
        $codec = new Codec(deflateMinBytes: PHP_INT_MAX);

        $this->assertSame($vector->packed, $codec->encode($vector->data, 'p')->body);
    }

    #[DataProvider('vectors')]
    public function test_packed_vectors_decode_to_the_original_data(object $vector): void
    {
        $decoded = (new Codec)->decode($vector->packed);

        $this->assertSame(
            json_encode($vector->data, self::FLAGS),
            json_encode($decoded, self::FLAGS),
        );
    }

    #[DataProvider('deflateVectors')]
    public function test_deflate_vectors_decode_to_the_original_data(object $vector): void
    {
        $codec = new Codec;

        $expected = json_encode($vector->data, self::FLAGS);

        $this->assertSame($expected, json_encode($codec->decode($vector->envelope), self::FLAGS));
    }

    #[DataProvider('deflateVectors')]
    public function test_dart_generated_envelopes_decode_to_the_original_data(object $vector): void
    {
        $codec = new Codec;

        $this->assertSame(
            json_encode($vector->data, self::FLAGS),
            json_encode($codec->decode($vector->dartEnvelope), self::FLAGS),
        );
    }
}
