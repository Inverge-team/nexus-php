<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\NexusClient;

/**
 * Realtime + telemetry through Nexus: emit to rooms, track events, write logs,
 * capture errors. Add `NexusRealtimeChannel::class` (or `'nexus-realtime'`) to
 * `via()` and return a {@see NexusRealtimeMessage} from `toNexusRealtime()`:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return ['nexus-realtime'];
 * }
 *
 * public function toNexusRealtime(object $notifiable): NexusRealtimeMessage
 * {
 *     return NexusRealtimeMessage::create()
 *         ->emit("orders:{$notifiable->id}", 'status', ['state' => 'shipped'])
 *         ->event('order_shipped');
 * }
 * ```
 *
 * The notifiable's `routeNotificationForNexusRealtime()` /
 * `routeNotificationForNexus()` (a distinctId string or identity array) is the
 * default identity. `toNexus()` — the method's former name — still works.
 */
final class NexusRealtimeChannel
{
    public const NAME = 'nexus-realtime';

    public function __construct(private readonly NexusClient $nexus)
    {
    }

    /** The notification method this channel reads (the current name, else the legacy `toNexus`). */
    public static function method(object $notification): ?string
    {
        foreach (['toNexusRealtime', 'toNexus'] as $method) {
            if (method_exists($notification, $method)) {
                return $method;
            }
        }

        return null;
    }

    /** @return true|null true when something was sent, null when there was nothing to send */
    public function send(object $notifiable, object $notification): ?bool
    {
        $method = self::method($notification);
        if ($method === null) {
            return null;
        }

        /** @var NexusRealtimeMessage|string|null $message */
        $message = $notification->{$method}($notifiable);

        if ($message === null) {
            return null;
        }

        if (is_string($message)) {
            $message = NexusRealtimeMessage::create()->info($message);
        }

        if (!$message instanceof NexusRealtimeMessage) {
            if ($method === 'toNexus') {
                return null; // the legacy channel ignored anything else — keep that
            }
            throw new \UnexpectedValueException(sprintf('%s::%s() must return a %s.', $notification::class, $method, NexusRealtimeMessage::class));
        }

        if (method_exists($notifiable, 'routeNotificationFor')) {
            $message->applyRoute(
                $notifiable->routeNotificationFor(self::NAME, $notification)
                    ?? $notifiable->routeNotificationFor('nexus', $notification),
            );
        }

        $message->send($this->nexus);

        return true;
    }
}
