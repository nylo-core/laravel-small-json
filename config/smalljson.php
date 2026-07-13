<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled, response()->smallJson() and the middleware
    | behave exactly like response()->json() — handy for debugging or for
    | rolling the feature out gradually.
    |
    */

    'enabled' => env('SMALLJSON_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Modes
    |--------------------------------------------------------------------------
    |
    | The encodings this server is willing to produce. "p" is the packed
    | structural encoding, "z" additionally deflates large payloads. The
    | response uses the best mode both sides support.
    |
    */

    'modes' => env('SMALLJSON_MODES', 'pz'),

    /*
    |--------------------------------------------------------------------------
    | Negotiate
    |--------------------------------------------------------------------------
    |
    | When true (recommended), responses are only SmallJson-encoded for
    | clients that advertise support via the request header below; everyone
    | else (browsers, Postman, webhooks…) receives plain JSON. Set to false
    | only when you control every consumer of the API.
    |
    */

    'negotiate' => env('SMALLJSON_NEGOTIATE', true),

    'request_header' => 'X-Small-Json',

    /*
    |--------------------------------------------------------------------------
    | Content type
    |--------------------------------------------------------------------------
    |
    | Sent on encoded responses so clients know to decode them. The "+json"
    | suffix keeps generic JSON tooling (and Dio) parsing the envelope.
    |
    */

    'content_type' => 'application/vnd.smalljson+json',

    /*
    |--------------------------------------------------------------------------
    | Size guards
    |--------------------------------------------------------------------------
    |
    | min_bytes: skip encoding entirely for plain payloads smaller than this.
    | only_when_smaller: if the encoded envelope is not actually smaller than
    | the plain JSON, send plain JSON — clients handle both transparently.
    |
    */

    'min_bytes' => env('SMALLJSON_MIN_BYTES', 0),

    'only_when_smaller' => true,

    /*
    |--------------------------------------------------------------------------
    | Deflate (mode "z")
    |--------------------------------------------------------------------------
    |
    | Only attempted when the client accepts "z" and the packed envelope is at
    | least min_bytes long. Level 6 is the usual speed/size sweet spot. If your
    | web server already gzips responses you may prefer 'modes' => 'p'.
    |
    */

    'deflate' => [
        'min_bytes' => env('SMALLJSON_DEFLATE_MIN_BYTES', 1024),
        'level' => env('SMALLJSON_DEFLATE_LEVEL', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stats header
    |--------------------------------------------------------------------------
    |
    | When enabled, encoded responses include an X-Small-Json-Stats header
    | (mode, plain size, sent size, % saved). Useful while evaluating the
    | package; leave off in production to save the bytes.
    |
    */

    'stats_header' => env('SMALLJSON_STATS', false),

];
