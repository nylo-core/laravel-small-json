<?php

declare(strict_types=1);

namespace SmallJson\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use SmallJson\SmallJsonManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-encodes any JsonResponse as SmallJson for clients that advertise
 * support — including resource responses, paginators and JSON error
 * responses — with zero changes to controller code.
 *
 * Register per-route/group with the "smalljson" alias, or globally in the
 * api middleware group.
 */
class SmallJsonResponses
{
    public function __construct(protected SmallJsonManager $manager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $request->isMethod('HEAD')) {
            return $response;
        }

        // Even when we leave the body plain, the URL's representation depends
        // on the negotiation header — shared caches need to know.
        $this->manager->addVary($response);

        $modes = $this->manager->negotiatedModes($request);

        if ($modes === '') {
            return $response;
        }

        $json = $response->getContent();

        if (! is_string($json) || $json === '' || strlen($json) < (int) config('smalljson.min_bytes', 0)) {
            return $response;
        }

        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $response; // JSONP or otherwise not plain JSON — leave untouched.
        }

        $payload = $this->manager->encodeValue($value, $modes);

        if (config('smalljson.only_when_smaller', true) && $payload->bytes() >= strlen($json)) {
            return $response;
        }

        $response->setJson($payload->body);
        $response->headers->set('Content-Type', $this->manager->contentType().'; charset=utf-8');
        $this->manager->applyStats($response, $payload, strlen($json));

        return $response;
    }
}
