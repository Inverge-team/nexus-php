<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\Services;
use Inverge\Nexus\NexusClient;

/**
 * Live Activities through Nexus — the iOS Lock Screen / Dynamic Island and the
 * Android 16 Live Update. Add `NexusLiveActivityChannel::class` (or
 * `'nexus-live-activity'`) to `via()` and return a {@see NexusLiveActivityMessage}
 * from `toNexusLiveActivity()`:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return ['nexus-push', 'nexus-live-activity'];
 * }
 *
 * public function toNexusLiveActivity(object $notifiable): NexusLiveActivityMessage
 * {
 *     return NexusLiveActivityMessage::create("order_{$this->order->id}")->update()->step('arrived');
 * }
 * ```
 *
 * A start is sent to the notifiable's `routeNotificationForNexusLiveActivity()` /
 * `routeNotificationForNexus()` (its distinctId) unless the message targets users
 * or is `shared()`; updates and ends address the activity by id. Return `null`
 * from `toNexusLiveActivity()` to skip.
 */
final class NexusLiveActivityChannel
{
    public const NAME = 'nexus-live-activity';

    public function __construct(private readonly NexusClient $nexus)
    {
    }

    /** @return array<mixed>|null the API result ({ ios, android, … }), null when nothing was sent */
    public function send(object $notifiable, object $notification): ?array
    {
        if (!method_exists($notification, 'toNexusLiveActivity')) {
            return null;
        }
        if (!$this->nexus->enabled(Services::LIVE_ACTIVITIES)) {
            return null; // switched off via NEXUS_LIVE_ACTIVITIES_ENABLED — skip without building the message
        }

        $message = $notification->toNexusLiveActivity($notifiable);
        if ($message === null) {
            return null;
        }
        if (!$message instanceof NexusLiveActivityMessage) {
            throw new \UnexpectedValueException(sprintf(
                '%s::toNexusLiveActivity() must return a %s.', $notification::class, NexusLiveActivityMessage::class,
            ));
        }

        $recipients = [];
        if ($message->action() === NexusLiveActivityMessage::START && !$message->isTargeted()) {
            $recipients = NexusRecipients::for($notifiable, $notification, self::NAME);
            if ($recipients === []) {
                throw NexusRecipients::missing($notifiable, self::NAME);
            }
        }

        return $message->sendWith($this->nexus->liveActivities(), $recipients);
    }
}
