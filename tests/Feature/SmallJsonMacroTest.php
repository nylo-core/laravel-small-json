<?php

declare(strict_types=1);

namespace SmallJson\Tests\Feature;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use SmallJson\Facades\SmallJson;
use SmallJson\Tests\TestCase;

class SmallJsonMacroTest extends TestCase
{
    public function test_encodes_for_clients_that_advertise_support(): void
    {
        $users = $this->users();
        Route::get('/users', fn () => response()->smallJson($users));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $response->assertOk();
        $this->assertStringStartsWith('application/vnd.smalljson+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('X-Small-Json', (string) $response->headers->get('Vary'));

        $decoded = SmallJson::decode($response->getContent(), true);
        $this->assertSame($users, $decoded);
    }

    public function test_encoded_responses_are_smaller_than_plain_json(): void
    {
        $users = $this->users();
        Route::get('/users', fn () => response()->smallJson($users));

        $encoded = $this->get('/users', ['X-Small-Json' => 'pz'])->getContent();

        $this->assertLessThan(strlen(json_encode($users)), strlen($encoded));
    }

    public function test_clients_without_the_header_get_plain_json(): void
    {
        $users = $this->users();
        Route::get('/users', fn () => response()->smallJson($users));

        $response = $this->get('/users');

        $response->assertOk();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('X-Small-Json', (string) $response->headers->get('Vary'));
        $this->assertSame(json_encode($users), $response->getContent());
    }

    public function test_disabled_config_always_produces_plain_json(): void
    {
        config()->set('smalljson.enabled', false);

        Route::get('/users', fn () => response()->smallJson($this->users()));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_negotiation_can_be_disabled_for_fully_controlled_apis(): void
    {
        config()->set('smalljson.negotiate', false);

        Route::get('/users', fn () => response()->smallJson($this->users()));

        $response = $this->get('/users'); // no header at all

        $this->assertStringStartsWith('application/vnd.smalljson+json', (string) $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Vary'));
    }

    public function test_payloads_that_would_grow_stay_plain(): void
    {
        Route::get('/ping', fn () => response()->smallJson(['ok' => true]));

        $response = $this->get('/ping', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('{"ok":true}', $response->getContent());
    }

    public function test_min_bytes_skips_encoding_of_small_payloads(): void
    {
        config()->set('smalljson.min_bytes', 1024 * 1024);

        Route::get('/users', fn () => response()->smallJson($this->users()));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_deflate_mode_is_used_when_accepted_and_worthwhile(): void
    {
        config()->set('smalljson.deflate.min_bytes', 64);

        $users = $this->users(60);
        Route::get('/users', fn () => response()->smallJson($users));

        $withZ = $this->get('/users', ['X-Small-Json' => 'pz'])->getContent();
        $packedOnly = $this->get('/users', ['X-Small-Json' => 'p'])->getContent();

        $this->assertStringContainsString('"m":"z"', $withZ);
        $this->assertStringContainsString('"m":"p"', $packedOnly);
        $this->assertSame(SmallJson::decode($packedOnly, true), SmallJson::decode($withZ, true));
    }

    public function test_matches_response_json_for_resources(): void
    {
        $user = ['id' => 1, 'name' => 'Anthony', 'roles' => ['admin', 'dev']];

        Route::get('/plain', fn () => response()->json(new JsonResource($user)));
        Route::get('/small', fn () => response()->smallJson(new JsonResource($user)));

        $plain = $this->get('/plain')->getContent();
        $small = $this->get('/small', ['X-Small-Json' => 'pz'])->getContent();

        // Not worth encoding at this size, so both are plain — and identical.
        $this->assertSame($plain, $small);
    }

    public function test_status_and_custom_headers_are_preserved(): void
    {
        Route::get('/users', fn () => response()->smallJson($this->users(), 201, ['X-Custom' => 'yes']));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $response->assertStatus(201);
        $this->assertSame('yes', $response->headers->get('X-Custom'));
    }

    public function test_stats_header_reports_savings_when_enabled(): void
    {
        config()->set('smalljson.stats_header', true);

        Route::get('/users', fn () => response()->smallJson($this->users()));

        $stats = (string) $this->get('/users', ['X-Small-Json' => 'pz'])->headers->get('X-Small-Json-Stats');

        $this->assertMatchesRegularExpression('/^mode=[pz]; plain=\d+; sent=\d+; saved=\d+\.\d%$/', $stats);
    }

    public function test_facade_encode_and_decode_round_trip(): void
    {
        $data = ['nested' => ['values' => [1, 2, 3]], 'flag' => true];

        $envelope = SmallJson::encode($data);

        $this->assertSame($data, SmallJson::decode($envelope, true));
    }

    public function test_an_existing_vary_header_is_not_duplicated(): void
    {
        Route::get('/users', fn () => response()->smallJson($this->users(), 200, ['Vary' => 'x-small-json']));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/vnd.smalljson+json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('x-small-json', $response->headers->get('Vary'));
    }

    public function test_encoding_invalid_utf8_fails_loudly(): void
    {
        $this->expectException(\SmallJson\Exceptions\EncodeException::class);

        SmallJson::encode(["\xB1\x31"]);
    }

    public function test_a_jsonable_returning_invalid_json_fails_loudly(): void
    {
        $broken = new class implements \Illuminate\Contracts\Support\Jsonable
        {
            public function toJson($options = 0): string
            {
                return 'this is not json';
            }
        };

        $this->expectException(\SmallJson\Exceptions\EncodeException::class);

        SmallJson::encode($broken);
    }
}
