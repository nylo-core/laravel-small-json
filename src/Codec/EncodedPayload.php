<?php

declare(strict_types=1);

namespace SmallJson\Codec;

final class EncodedPayload
{
    public function __construct(
        public readonly string $mode,
        public readonly string $body,
    ) {
    }

    public function bytes(): int
    {
        return strlen($this->body);
    }
}
