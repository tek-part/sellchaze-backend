<?php

namespace App\Exceptions;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $quotaKey,
        public readonly int $limit,
        public readonly int $used,
    ) {
        parent::__construct("The {$quotaKey} quota has been exceeded.");
    }
}
