<?php

declare(strict_types=1);

namespace Inverge\Nexus\Exception;

/**
 * The organization's API access is suspended for an overdue invoice (HTTP 402
 * `billing_suspended`).
 *
 * After the first one the SDK stops calling the API for {@see $retryAfter}
 * seconds: every further call fails locally with this exception (telemetry is
 * dropped silently), so a suspended app sends nothing. Access returns on its own
 * once the invoice is settled — the next call after the pause checks again.
 */
final class BillingSuspendedException extends ApiException
{
    /**
     * @param int               $retryAfter seconds until the SDK calls the API again
     * @param bool              $local      true when refused by the SDK without a request
     * @param array<mixed>|null $details    the API's suspension details (amount due, invoices)
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter,
        public readonly bool $local = false,
        ?array $details = null,
    ) {
        parent::__construct($message, 402, 'billing_suspended', $details);
    }
}
