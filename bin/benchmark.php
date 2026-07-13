#!/usr/bin/env php
<?php

/**
 * SmallJson size benchmark on deterministic, realistic API payloads.
 *
 *   composer bench   (or: php bin/benchmark.php)
 *
 * The gzip columns show what web-server compression would do, so you can see
 * what SmallJson adds when HTTP compression is available — and when it isn't.
 */

declare(strict_types=1);

$autoload = __DIR__.'/../vendor/autoload.php';

if (file_exists($autoload)) {
    require $autoload;
} else {
    foreach (['Exceptions/SmallJsonException', 'Exceptions/EncodeException', 'Exceptions/DecodeException', 'Codec/Packer', 'Codec/EncodedPayload', 'Codec/Codec'] as $class) {
        require __DIR__.'/../src/'.$class.'.php';
    }
}

use SmallJson\Codec\Codec;

const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function users(int $count): array
{
    $users = [];

    for ($i = 1; $i <= $count; $i++) {
        $users[] = [
            'id' => $i,
            'uuid' => sprintf('%08x-abcd-4000-8000-%012x', $i * 2654435761 % 4294967296, $i * 1099511627776 % 281474976710656),
            'name' => 'User '.$i,
            'email' => 'user'.$i.'@example.com',
            'email_verified_at' => $i % 3 === 0 ? null : '2026-01-0'.($i % 9 + 1).'T10:00:00.000000Z',
            'active' => $i % 2 === 0,
            'balance' => round($i * 3.33 + 0.01, 2),
            'created_at' => '2025-1'.($i % 2).'-0'.($i % 9 + 1).'T09:30:00.000000Z',
            'updated_at' => '2026-0'.($i % 9 + 1).'-15T18:45:00.000000Z',
            'address' => [
                'line1' => ($i * 7 % 200).' Sample Street',
                'city' => ['London', 'Manchester', 'Leeds', 'Bristol'][$i % 4],
                'postcode' => 'SW'.($i % 20).' '.($i % 9).'AA',
                'country' => 'GB',
            ],
            'roles' => $i % 5 === 0 ? ['admin', 'user'] : ['user'],
        ];
    }

    return $users;
}

$payloads = [
    'users index (100 rows + meta)' => [
        'data' => users(100),
        'links' => ['first' => 'https://api.example.com/users?page=1', 'last' => 'https://api.example.com/users?page=4', 'prev' => null, 'next' => 'https://api.example.com/users?page=2'],
        'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 4, 'per_page' => 100, 'to' => 100, 'total' => 400],
    ],
    'users index (15 rows)' => ['data' => users(15)],
    'single user + 10 posts' => [
        'data' => users(1)[0] + ['posts' => array_map(fn ($i) => [
            'id' => $i,
            'title' => 'Post number '.$i,
            'excerpt' => 'A short excerpt for post '.$i.' with a bit of text in it.',
            'published' => $i % 2 === 0,
            'comments_count' => $i * 3,
        ], range(1, 10))],
    ],
];

$codec = new Codec(deflateLevel: 6, deflateMinBytes: 0);
$packedCodec = new Codec(deflateLevel: 6, deflateMinBytes: PHP_INT_MAX);

$fmt = fn (int $bytes, int $plain) => sprintf('%6s KB  %5.1f%%', number_format($bytes / 1000, 1), 100 - $bytes / $plain * 100);

foreach ($payloads as $name => $payload) {
    $value = json_decode(json_encode($payload, FLAGS), false);
    $plain = json_encode($value, FLAGS);
    $packed = $packedCodec->encode($value, 'p');
    $deflated = $codec->encode($value, 'pz');

    $plainLen = strlen($plain);

    echo "\n{$name}\n";
    echo str_repeat('-', 58)."\n";
    printf("  %-28s %6s KB\n", 'plain JSON', number_format($plainLen / 1000, 1));
    printf("  %-28s %s\n", 'smalljson (p)', $fmt($packed->bytes(), $plainLen));
    printf("  %-28s %s\n", 'smalljson (z)', $fmt($deflated->bytes(), $plainLen));
    printf("  %-28s %s\n", 'plain + http gzip', $fmt(strlen(gzencode($plain, 6)), $plainLen));
    printf("  %-28s %s\n", 'smalljson (p) + http gzip', $fmt(strlen(gzencode($packed->body, 6)), $plainLen));

    // Confirm fidelity while we're here.
    if (json_encode($codec->decode($deflated->body), FLAGS) !== $plain || json_encode($codec->decode($packed->body), FLAGS) !== $plain) {
        fwrite(STDERR, "FIDELITY CHECK FAILED for {$name}\n");
        exit(1);
    }
}

echo "\nAll payloads decoded back to byte-identical JSON.\n";
