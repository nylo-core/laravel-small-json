# SmallJson for Laravel

Shrink your API's JSON responses with a compact, reversible structural encoding — plus
optional deflate — and serve them from a familiar one-liner:

```php
return response()->smallJson(new UserResource($user));
```

Clients that advertise support get the small payload; everyone else transparently gets plain
JSON. The [`smalljson`](https://pub.dev/packages/smalljson) Flutter package decodes it with a
drop-in Dio interceptor, so your app code never knows the encoding happened.

## How it works

SmallJson pulls every object key into a single key table and collapses uniform collections
into row tuples:

```jsonc
// before — 133 bytes
[
  {"id": 1, "name": "Ada",   "email": "ada@example.com"},
  {"id": 2, "name": "Grace", "email": "grace@example.com"},
  {"id": 3, "name": "Alan",  "email": "alan@example.com"}
]

// after — 118 bytes here; the key savings multiply with every row
{"_sj":1,"m":"p","k":["id","name","email"],"b":[2,[0,1,2],[1,"Ada","ada@example.com"],[2,"Grace","grace@example.com"],[3,"Alan","alan@example.com"]]}
```

For large payloads it can additionally deflate the result (mode `z`).

> **This is compression, not encryption.** The payload is obfuscated to casual eyes, but
> anyone with the (open) spec can decode it — confidentiality comes from TLS.

Measured on realistic Laravel API payloads (`composer bench`):

_All sizes in KB; % saved vs plain JSON._

| Payload                        | plain JSON | smalljson `p`     | smalljson `z`   | plain + gzip   | `p` + gzip     |
|--------------------------------|-----------:|------------------:|----------------:|---------------:|---------------:|
| users index, 100 rows + meta   | 37.5 KB    | 24.7 KB (−34.0%) | 5.2 KB (−86.0%) | 4.0 KB (−89.3%) | 3.9 KB (−89.5%) |
| users index, 15 rows           | 5.5 KB     | 3.8 KB (−31.4%)  | 1.2 KB (−78.7%) | 0.9 KB (−83.8%) | 0.9 KB (−83.8%) |
| single user + 10 posts         | 1.7 KB     | 1.4 KB (−21.6%)  | 0.7 KB (−62.0%) | 0.5 KB (−73.3%) | 0.5 KB (−71.0%) |

<details>
<summary>Full benchmark output</summary>

```
users index (100 rows + meta)
----------------------------------------------------------
  plain JSON                     37.5 KB
  smalljson (p)                  24.7 KB   34.0%
  smalljson (z)                   5.2 KB   86.0%
  plain + http gzip               4.0 KB   89.3%
  smalljson (p) + http gzip       3.9 KB   89.5%

users index (15 rows)
----------------------------------------------------------
  plain JSON                      5.5 KB
  smalljson (p)                   3.8 KB   31.4%
  smalljson (z)                   1.2 KB   78.7%
  plain + http gzip               0.9 KB   83.8%
  smalljson (p) + http gzip       0.9 KB   83.8%

single user + 10 posts
----------------------------------------------------------
  plain JSON                      1.7 KB
  smalljson (p)                   1.4 KB   21.6%
  smalljson (z)                   0.7 KB   62.0%
  plain + http gzip               0.5 KB   73.3%
  smalljson (p) + http gzip       0.5 KB   71.0%
```

</details>

Read that table honestly: **if your web server already gzips JSON responses, SmallJson's
byte savings are modest.** It shines when HTTP compression isn't in play — chunked/streamed
responses, misconfigured proxies, shared hosting, internal service calls — and mode `z`
brings its own compression with it. When the encoded form wouldn't be smaller, SmallJson
automatically sends plain JSON instead, so it never costs you bytes.

## Requirements

- PHP 8.1+ (`ext-json`, `ext-zlib`)
- Laravel 10, 11, 12 or 13

## Installation

```bash
composer require nylo/smalljson
```

The service provider and `SmallJson` facade are auto-discovered. Optionally publish the
config:

```bash
php artisan vendor:publish --tag=smalljson-config
```

## Usage

### The macro

Works exactly like `response()->json()` — same arguments, same serialisation semantics:

```php
Route::get('/users', function () {
    return response()->smallJson(User::query()->paginate());
});

return response()->smallJson(new UserResource($user), 201, ['X-Request-Id' => $id]);
```

### The middleware (zero controller changes)

Prefer this if you want existing endpoints — resource responses, paginators, even JSON
validation errors — encoded without touching any controller:

```php
// per route / group
Route::middleware('smalljson')->group(function () {
    Route::apiResource('users', UserController::class);
});

// or for the whole API, in bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [\SmallJson\Http\Middleware\SmallJsonResponses::class]);
})
```

The middleware only rewrites `JsonResponse`s, skips `HEAD` requests, and leaves anything
that isn't plain JSON (JSONP, binary, streams) untouched.

> Returning a resource from a route wraps it in `data` before the middleware runs, so the
> wrapper is preserved. The macro mirrors `response()->json()` instead, which — like
> Laravel itself — does not apply resource wrapping.

### Manual encode/decode

```php
use SmallJson\Facades\SmallJson;

$envelope = SmallJson::encode($data);          // string, honours config modes
$data = SmallJson::decode($envelope, true);    // assoc arrays; false for stdClass
```

Useful for websockets, queued payloads, and cache entries. The codec itself
(`SmallJson\Codec\Codec`) is framework-free if you need it outside Laravel.

## Negotiation

By default nothing changes for clients that don't opt in:

1. A capable client sends `X-Small-Json: pz` (the modes it accepts). The Flutter
   interceptor does this automatically.
2. The server responds with `Content-Type: application/vnd.smalljson+json` and the encoded
   body — or plain JSON when encoding wouldn't help. `Vary: X-Small-Json` is set either
   way so shared caches keep the variants apart.

Set `'negotiate' => false` to encode for every client — only do this when you control all
consumers. Browsers calling your API with the header from JavaScript will need
`X-Small-Json` whitelisted in your CORS `allowed_headers`.

## Configuration

| Key                  | Default                          | Meaning                                            |
|----------------------|----------------------------------|----------------------------------------------------|
| `enabled`            | `true` (`SMALLJSON_ENABLED`)     | Master switch; off = plain JSON everywhere         |
| `modes`              | `'pz'` (`SMALLJSON_MODES`)       | Encodings the server may produce                   |
| `negotiate`          | `true` (`SMALLJSON_NEGOTIATE`)   | Require the request header before encoding         |
| `request_header`     | `'X-Small-Json'`                 | Capability header name                             |
| `content_type`       | `application/vnd.smalljson+json` | Marker content type on encoded responses           |
| `min_bytes`          | `0` (`SMALLJSON_MIN_BYTES`)      | Skip encoding below this plain-JSON size           |
| `only_when_smaller`  | `true`                           | Fall back to plain JSON unless encoding shrinks it |
| `deflate.min_bytes`  | `1024`                           | Only try mode `z` at or above this envelope size   |
| `deflate.level`      | `6`                              | DEFLATE level (1–9)                                |
| `stats_header`       | `false` (`SMALLJSON_STATS`)      | Add `X-Small-Json-Stats` with the savings          |

Tip: while evaluating, set `SMALLJSON_STATS=true` and watch the header:
`X-Small-Json-Stats: mode=z; plain=37462; sent=5236; saved=86.0%`.

## Clients

- **Flutter / Dart** — the [`smalljson`](https://pub.dev/packages/smalljson) package's
  `SmallJsonInterceptor` (Dio). Advertises support, decodes bodies (including error
  responses), rewrites the content type back to `application/json`.
- **Browser JS** — the packed format decodes in ~30 lines of JavaScript (mode `z`
  additionally needs `new DecompressionStream('deflate-raw')`).
- **PHP** — `SmallJson::decode()` in this package.

## Testing

```bash
composer test    # unit + feature (testbench) + cross-implementation vectors; 100% line coverage
composer bench   # the size benchmark shown above
```

The suite includes shared wire-format vectors (`tests/Fixtures/vectors.json`) verified
byte-for-byte against the Dart implementation — both directions.

## License

MIT © [Anthony Gordon](https://nylo.dev)
