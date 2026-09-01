<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Illuminate\Notifications\Notification;
use Inverge\Nexus\NexusClient;

/**
 * Laravel notification channel. Add `NexusChannel::class` (or `'nexus'`) to a
 * notification's `via()` and return a {@see NexusMessage} from `toNexus()`:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return [NexusChannel::class];
 * }
 *
 * public function toNexus(object $notifiable): NexusMessage
 * {
 *     return NexusMessage::create()
 *         ->emit("orders:{$notifiable->id}", 'status', ['state' => 'shipped'])
 *         ->event('order_shipped');
 * }
 * ```
 *
 * The notifiable's `routeNotificationForNexus()` (a distinctId string or identity
 * array) is applied as the default identity. Make the notification implement
 * `ShouldQueue` to deliver off-request.
 */
final class NexusChannel
{
    public function __construct(private readonly NexusClient $nexus)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toNexus')) {
            return;
        }

        /** @var NexusMessage|string|null $message */
        $message = $notification->toNexus($notifiable);

        if ($message === null) {
            return;
        }

        if (is_string($message)) {
            $message = NexusMessage::create()->info($message);
        }

        if (!$message instanceof NexusMessage) {
            return;
        }

        if (method_exists($notifiable, 'routeNotificationFor')) {
            $message->applyRoute($notifiable->routeNotificationFor('nexus', $notification));
        }

        $message->send($this->nexus);
    }
}
