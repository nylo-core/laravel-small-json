<?php

declare(strict_types=1);

namespace SmallJson\Facades;

use Illuminate\Support\Facades\Facade;
use SmallJson\SmallJsonManager;

/**
 * @method static \Symfony\Component\HttpFoundation\Response response(mixed $data = [], int $status = 200, array $headers = [], int $options = 0)
 * @method static string encode(mixed $data, ?string $modes = null, int $options = 0)
 * @method static mixed decode(string|\stdClass|array $payload, bool $assoc = false)
 * @method static string negotiatedModes(?\Illuminate\Http\Request $request = null)
 *
 * @see \SmallJson\SmallJsonManager
 */
class SmallJson extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmallJsonManager::class;
    }
}
