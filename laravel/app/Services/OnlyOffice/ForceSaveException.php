<?php

namespace App\Services\OnlyOffice;

use RuntimeException;

class ForceSaveException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $responseStatus = 409,
    ) {
        parent::__construct($message);
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }
}
