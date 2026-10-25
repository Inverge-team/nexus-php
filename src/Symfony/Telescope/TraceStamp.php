<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\Telescope;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Carries the Telescope trace across a Messenger transport. */
final class TraceStamp implements StampInterface
{
    public function __construct(public readonly string $traceId, public readonly string $parentSpanId)
    {
    }
}
