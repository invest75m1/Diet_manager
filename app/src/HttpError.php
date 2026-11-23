<?php
declare(strict_types=1);

namespace App;

final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message, $status);
    }
}
