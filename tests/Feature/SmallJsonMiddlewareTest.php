<?php

declare(strict_types=1);

namespace SmallJson\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use SmallJson\Facades\SmallJson;
use SmallJson\Tests\TestCase;

class SmallJsonMiddlewareTest extends TestCase
{
    public function test_encodes_json_responses_without_controller_changes(): void
    {
        $users = $this->users();
        Route::middleware('smalljson')->get('/users', fn () => response()->json($users));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $response->assertOk();
        $this->assertStringStartsWith('application/vnd.smalljson+json', (string) $response->headers->get('Content-Type'));
        $this->assertSame($users, SmallJson::decode($response->getContent(), true));
    }

    public function test_resource_responses_keep_their_data_wrapper(): void
    {
        Route::middleware('smalljson')->get('/user', fn () => new JsonResource($this->users(20)));

        $response = $this->get('/user', ['X-Small-Json' => 'pz']);

        $decoded = SmallJson::decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame($this->users(20), $decoded['data']);
    }

    public function test_clients_without_the_header_are_untouched_but_vary_is_set(): void
    {
        $users = $this->users();
        Route::middleware('smalljson')->get('/users', fn () => response()->json($users));

        $response = $this->get('/users');

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('X-Small-Json', (string) $response->headers->get('Vary'));
        $this->assertSame(json_encode($users), $response->getContent());
    }

    public function test_error_json_responses_are_encoded_too(): void
    {
        config()->set('smalljson.only_when_smaller', false);

        Route::middleware('smalljson')->post('/form', function (Request $request) {
            return response()->json(['message' => 'Invalid.', 'errors' => ['name' => ['The name field is required.']]], 422);
        });

        $response = $this->post('/form', [], ['X-Small-Json' => 'pz', 'Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertStringStartsWith('application/vnd.smalljson+json', (string) $response->headers->get('Content-Type'));

        $decoded = SmallJson::decode($response->getContent(), true);
        $this->assertSame(['name' => ['The name field is required.']], $decoded['errors']);
    }

    public function test_non_json_responses_pass_through(): void
    {
        Route::middleware('smalljson')->get('/page', fn () => response('<h1>hello</h1>', 200, ['Content-Type' => 'text/html']));

        $response = $this->get('/page', ['X-Small-Json' => 'pz']);

        $this->assertSame('<h1>hello</h1>', $response->getContent());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_responses_that_would_grow_stay_plain(): void
    {
        Route::middleware('smalljson')->get('/ping', fn () => response()->json(['ok' => true]));

        $response = $this->get('/ping', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('{"ok":true}', $response->getContent());
    }

    public function test_macro_responses_are_not_double_encoded_by_the_middleware(): void
    {
        $users = $this->users();
        Route::middleware('smalljson')->get('/users', fn () => response()->smallJson($users));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertSame($users, SmallJson::decode($response->getContent(), true));
    }

    public function test_jsonp_responses_pass_through(): void
    {
        Route::middleware('smalljson')->get('/jsonp', fn () => response()->json(['ok' => true])->withCallback('cb'));

        $response = $this->get('/jsonp', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('/**/cb(', (string) $response->getContent());
        $this->assertStringNotContainsString('smalljson', (string) $response->headers->get('Content-Type'));
    }

    public function test_min_bytes_skips_encoding_of_small_payloads(): void
    {
        config()->set('smalljson.min_bytes', 1024 * 1024);

        $users = $this->users();
        Route::middleware('smalljson')->get('/users', fn () => response()->json($users));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(json_encode($users), $response->getContent());
    }

    public function test_disabled_config_leaves_responses_alone(): void
    {
        config()->set('smalljson.enabled', false);

        $users = $this->users();
        Route::middleware('smalljson')->get('/users', fn () => response()->json($users));

        $response = $this->get('/users', ['X-Small-Json' => 'pz']);

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(json_encode($users), $response->getContent());
    }
}
