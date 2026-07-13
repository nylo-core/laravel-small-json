<?php

declare(strict_types=1);

namespace SmallJson\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SmallJson\SmallJsonServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [SmallJsonServiceProvider::class];
    }

    /**
     * A payload that is large and repetitive enough for every mode to engage.
     * Normalised through JSON once so equality assertions compare what a JSON
     * client would actually see (e.g. 65.0 encodes as 65, like in any
     * response()->json()).
     */
    protected function users(int $count = 30): array
    {
        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $users[] = [
                'id' => $i,
                'name' => 'User '.$i,
                'email' => 'user'.$i.'@example.com',
                'email_verified_at' => $i % 3 === 0 ? null : '2026-01-0'.($i % 9 + 1).'T10:00:00Z',
                'active' => $i % 2 === 0,
                'balance' => round($i * 3.25, 2),
                'address' => [
                    'line1' => $i.' Sample Street',
                    'city' => 'London',
                    'country' => 'GB',
                ],
            ];
        }

        return json_decode(json_encode($users), true);
    }
}
