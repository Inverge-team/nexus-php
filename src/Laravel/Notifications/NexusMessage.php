<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

/*
 * `NexusMessage` was renamed to {@see NexusRealtimeMessage} (returned from
 * `toNexusRealtime()`). The alias keeps existing notifications — and
 * `instanceof NexusMessage` checks — working unchanged.
 */
class_alias(NexusRealtimeMessage::class, NexusMessage::class);

if (false) {
    /** @deprecated use {@see NexusRealtimeMessage} */
    final class NexusMessage
    {
    }
}
