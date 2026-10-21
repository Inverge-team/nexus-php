<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\Services;
use Inverge\Nexus\NexusClient;

/**
 * Push notifications through Nexus (FCM / APNs / Web Push). Add
 * `NexusPushChannel::class` (or `'nexus-push'`) to `via()` and return a
 * {@see NexusPushMessage} from `toNexusPush()`:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return ['nexus-push'];
 * }
 *
 * public function toNexusPush(object $notifiable): NexusPushMessage
 * {
 *     return NexusPushMessage::create('Your order shipped', 'Track it in the app')
 *         ->data(['screen' => '/orders/42']);
 * }
 * ```
 *
 * Sent to the notifiable's `routeNotificationForNexusPush()` /
 * `routeNotificationForNexus()` (its distinctId) unless the message targets
 * users, segments or everyone itself. Implement `ShouldQueue` to send off-request.
 */
final class NexusPushChannel
{
    public const NAME = 'nexus-push';

    public function __construct(private readonly NexusClient $nexus)
    {
    }

    /** @return array<mixed>|null the API result ({ sent, failed, recipients }), null when nothing was sent */
    public function send(object $notifiable, object $notification): ?array
    {
        if (!method_exists($notification, 'toNexusPush')) {
            return null;
        }
        if (!$this->nexus->enabled(Services::PUSH)) {
            return null; // switched off via NEXUS_PUSH_ENABLED — skip without building the message
        }

        $message = $notification->toNexusPush($notifiable);
        if ($message === null) {
            return null;
        }
        if (!$message instanceof NexusPushMessage) {
            throw new \UnexpectedValueException(sprintf('%s::toNexusPush() must return a %s.', $notification::class, NexusPushMessage::class));
        }

        $builder = $message->applyTo($this->nexus->push()->notification());
        if (!$message->isTargeted()) {
            $to = NexusRecipients::for($notifiable, $notification, self::NAME);
            if ($to === []) {
                throw NexusRecipients::missing($notifiable, self::NAME);
            }
            $builder->toUsers($to);
        }

        return $builder->send();
    }
}
