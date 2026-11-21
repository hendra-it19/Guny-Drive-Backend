<?php

namespace App\Exceptions;

use RuntimeException;

class ApiErrorException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
