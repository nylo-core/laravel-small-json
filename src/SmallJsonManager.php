<?php

declare(strict_types=1);

namespace SmallJson;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use JsonSerializable;
use SmallJson\Codec\Codec;
use SmallJson\Codec\EncodedPayload;
use SmallJson\Exceptions\EncodeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SmallJsonManager
{
    /**
     * Build an HTTP response that is SmallJson-encoded when the client accepts
     * it and the encoding actually pays off; otherwise a plain JSON response.
     * Mirrors response()->json() semantics for all four arguments.
     */
    public function response(mixed $data = [], int $status = 200, array $headers = [], int $options = 0): SymfonyResponse
    {
        $json = $this->toJsonString($data, $options);
        $modes = $this->negotiatedModes();

        if ($modes === '' || strlen($json) < (int) config('smalljson.min_bytes', 0)) {
            return $this->plainResponse($json, $status, $headers);
        }

        $payload = $this->encodeValue($this->parseJson($json), $modes);

        if (config('smalljson.only_when_smaller', true) && $payload->bytes() >= strlen($json)) {
            return $this->plainResponse($json, $status, $headers);
        }

        $response = new Response($payload->body, $status, $headers);
        $response->headers->set('Content-Type', $this->contentType().'; charset=utf-8');
        $this->addVary($response);
        $this->applyStats($response, $payload, strlen($json));

        return $response;
    }

    /**
     * Encode any json-able data into a SmallJson envelope string, regardless
     * of request negotiation. Useful for queues, sockets, storage, caches.
     */
    public function encode(mixed $data, ?string $modes = null, int $options = 0): string
    {
        $modes ??= $this->serverModes();

        return $this->encodeValue($this->parseJson($this->toJsonString($data, $options)), $modes !== '' ? $modes : Codec::MODES)->body;
    }

    /**
     * Decode a SmallJson envelope (raw string or pre-parsed) back to data.
     *
     * @param  bool  $assoc  Return objects as associative arrays instead of stdClass.
     */
    public function decode(string|stdClass|array $payload, bool $assoc = false): mixed
    {
        return $this->codec()->decode($payload, $assoc);
    }

    /**
     * Encode a value already in json_decode() form. Used internally and by
     * the middleware.
     */
    public function encodeValue(mixed $value, string $modes): EncodedPayload
    {
        return $this->codec()->encode($value, $modes);
    }

    /**
     * The modes usable for the current request: the intersection of what this
     * server produces and what the client advertised. Empty string means
     * "respond with plain JSON".
     */
    public function negotiatedModes(?Request $request = null): string
    {
        if (! config('smalljson.enabled', true)) {
            return '';
        }

        $server = $this->serverModes();

        if (! config('smalljson.negotiate', true)) {
            return $server;
        }

        $request ??= request();
        $client = strtolower((string) $request?->header($this->requestHeader(), ''));

        $modes = '';

        foreach (str_split(Codec::MODES) as $mode) {
            if (str_contains($server, $mode) && str_contains($client, $mode)) {
                $modes .= $mode;
            }
        }

        return str_contains($modes, Codec::MODE_PACKED) ? $modes : '';
    }

    /**
     * Append the negotiation header to Vary so shared caches keep the plain
     * and SmallJson variants of a URL apart.
     */
    public function addVary(SymfonyResponse $response): void
    {
        if (! config('smalljson.negotiate', true)) {
            return;
        }

        $header = $this->requestHeader();
        $parts = array_values(array_filter(array_map(trim(...), explode(',', (string) $response->headers->get('Vary', '')))));

        foreach ($parts as $part) {
            if (strcasecmp($part, $header) === 0) {
                return;
            }
        }

        $parts[] = $header;
        $response->headers->set('Vary', implode(', ', $parts));
    }

    public function applyStats(SymfonyResponse $response, EncodedPayload $payload, int $plainBytes): void
    {
        if (! config('smalljson.stats_header', false) || $plainBytes < 1) {
            return;
        }

        $response->headers->set('X-Small-Json-Stats', sprintf(
            'mode=%s; plain=%d; sent=%d; saved=%.1f%%',
            $payload->mode,
            $plainBytes,
            $payload->bytes(),
            100 - ($payload->bytes() / $plainBytes * 100),
        ));
    }

    public function requestHeader(): string
    {
        return (string) config('smalljson.request_header', 'X-Small-Json');
    }

    public function contentType(): string
    {
        return (string) config('smalljson.content_type', Codec::CONTENT_TYPE);
    }

    /**
     * The modes this server is configured to produce.
     */
    protected function serverModes(): string
    {
        $configured = strtolower((string) config('smalljson.modes', Codec::MODES));
        $modes = '';

        foreach (str_split(Codec::MODES) as $mode) {
            if (str_contains($configured, $mode)) {
                $modes .= $mode;
            }
        }

        return $modes;
    }

    protected function codec(): Codec
    {
        $deflate = (array) config('smalljson.deflate', []);

        return new Codec(
            deflateLevel: (int) ($deflate['level'] ?? 6),
            deflateMinBytes: (int) ($deflate['min_bytes'] ?? 1024),
        );
    }

    protected function plainResponse(string $json, int $status, array $headers): JsonResponse
    {
        $response = new JsonResponse($json, $status, $headers, 0, true);
        $this->addVary($response);

        return $response;
    }

    /**
     * Serialise exactly the way Illuminate\Http\JsonResponse::setData() does,
     * so response()->smallJson() and response()->json() agree on every input.
     */
    protected function toJsonString(mixed $data, int $options): string
    {
        $json = match (true) {
            $data instanceof Jsonable => $data->toJson($options),
            $data instanceof JsonSerializable => json_encode($data->jsonSerialize(), $options),
            $data instanceof Arrayable => json_encode($data->toArray(), $options),
            default => json_encode($data, $options),
        };

        if (! is_string($json)) {
            throw new EncodeException('Could not encode data to JSON: '.json_last_error_msg());
        }

        return $json;
    }

    protected function parseJson(string $json): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EncodeException('Data did not serialise to valid JSON: '.$e->getMessage(), previous: $e);
        }
    }
}
