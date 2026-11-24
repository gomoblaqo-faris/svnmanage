<?php

declare(strict_types=1);

namespace SvnManage\Http;

use RuntimeException;

/** Aborts the request with an HTTP status rendered as an error page. */
final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly string $kind = 'error',
    ) {
        parent::__construct($message, $status);
    }

    public static function forbidden(string $message = 'You do not have permission to access this page.'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function notFound(string $message = 'The page you requested does not exist.'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function inactive(string $message = 'This module is disabled in the current configuration.'): self
    {
        return new self(404, $message, 'inactive');
    }
}
