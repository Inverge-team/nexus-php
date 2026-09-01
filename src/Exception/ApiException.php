<?php

declare(strict_types=1);

namespace Inverge\Nexus\Exception;

use Inverge\Nexus\Http\ApiResponse;

/** A non-2xx response from the Nexus API. */
class ApiException extends NexusException
{
    /**
     * @param array<mixed>|null $details validation details, when the API supplies them
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(ApiResponse $response): self
    {
        $body = $response->json();
        // The API returns { error: { code, message, details } } or a bare message.
        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;

        $message = (string) ($error['message'] ?? $body['message'] ?? "Nexus API request failed with status {$response->status}");
        $code = $error['code'] ?? null;
        $details = $error['details'] ?? null;

        return new self(
            message: $message,
            status: $response->status,
            errorCode: is_string($code) ? $code : null,
            details: is_array($details) ? $details : null,
        );
    }
}
