<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

use Inverge\Nexus\Exception\NexusException;

/**
 * DocStore is switched off (`NEXUS_DOCSTORE_ENABLED=false` / `services`). Most
 * services become silent no-ops when off; DocStore throws, because a read that
 * quietly returns nothing looks exactly like missing data.
 */
final class ServiceDisabledException extends NexusException
{
}
