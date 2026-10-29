<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;

final class WhatsAppException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $metaCode = 0,
        private readonly int $status = 0,
    ) {
        parent::__construct($message, $status);
    }

    /** Meta's error code from the response body (error.code), 0 when absent. */
    public function metaCode(): int
    {
        return $this->metaCode;
    }

    /** HTTP status of the failed response, 0 when no response was received. */
    public function status(): int
    {
        return $this->status;
    }

    public static function fromResponse(Response $response, string $action = 'WhatsApp request'): self
    {
        $body = $response->json();
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $error = is_array($error) ? $error : [];
        $code = (int) ($error['code'] ?? 0);
        $message = $error['message'] ?? null;
        if (! is_string($message) || $message === '') {
            $message = $response->body();
        }
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500).'...';
        }

        return new self(
            "{$action} failed with HTTP {$response->status()} (Meta code {$code}): {$message}",
            $code,
            $response->status()
        );
    }
}
