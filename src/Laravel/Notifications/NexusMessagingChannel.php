<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Services;

/**
 * Messages through Nexus — WhatsApp, Telegram, SMS, Viber and the other
 * messaging channels. Add `NexusMessagingChannel::class` (or
 * `'nexus-messaging'`) to `via()` and return a {@see NexusMessagingMessage}
 * (or a list of them) from `toNexusMessaging()`:
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return ['nexus-messaging'];
 * }
 *
 * public function toNexusMessaging(object $notifiable): NexusMessagingMessage
 * {
 *     return NexusMessagingMessage::whatsapp()
 *         ->template('order_shipped', 'en_US', ['order_id' => $this->order->number]);
 * }
 *
 * // on the notifiable
 * public function routeNotificationForNexusMessaging(object $notification): string|array
 * {
 *     return ['whatsapp' => $this->phone, 'telegram' => $this->telegram_chat_id];
 * }
 * ```
 *
 * The recipient is the message's `to()`, else the notifiable's
 * `routeNotificationForNexusMessaging()` — one address, or one per channel. On
 * demand: `Notification::route('nexus-messaging', '+9647501234567')`. (The
 * shared `routeNotificationForNexus()` is a distinctId, not an address, so it
 * is not used here.)
 *
 * Retry-safe when queued: each send's `Idempotency-Key` is derived from the
 * notification id, so a retried job gets the original message back instead of
 * sending a second one. Failures throw; returning `null` skips.
 */
final class NexusMessagingChannel
{
    public const NAME = 'nexus-messaging';

    public function __construct(private readonly NexusClient $nexus)
    {
    }

    /**
     * @return list<array<string, mixed>>|null each send's result (`id`, `status`, …), null when nothing was sent
     *
     * @throws \Throwable the first failure, after every message was attempted
     */
    public function send(object $notifiable, object $notification): ?array
    {
        if (!method_exists($notification, 'toNexusMessaging')) {
            return null;
        }
        if (!$this->nexus->enabled(Services::MESSAGING)) {
            return null; // switched off via NEXUS_MESSAGING_ENABLED — skip without building the message
        }

        $messages = $notification->toNexusMessaging($notifiable);
        if ($messages === null || $messages === []) {
            return null;
        }
        $messages = $messages instanceof NexusMessagingMessage ? [$messages] : $messages;
        if (!\is_array($messages) || !array_is_list($messages)) {
            throw self::wrongType($notification);
        }

        // Resolve every recipient first, so a missing one sends nothing at all.
        $sends = [];
        foreach ($messages as $message) {
            if (!$message instanceof NexusMessagingMessage) {
                throw self::wrongType($notification);
            }
            $sends[] = [$message, $message->recipient() ?? $this->route($notifiable, $notification, $message->channel())];
        }

        $results = [];
        $failure = null;
        foreach ($sends as $i => [$message, $to]) {
            try {
                $result = $message->sendWith(
                    $this->nexus->messaging(),
                    $to,
                    self::idempotencyKey($notifiable, $notification, $i, $message->channel(), $to),
                );
            } catch (\Throwable $e) {
                $failure ??= $e; // keep going — the others are independent sends
                continue;
            }
            if ($result !== null) {
                $results[] = $result;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $results === [] ? null : $results;
    }

    private function route(object $notifiable, object $notification, string $channel): string
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor(self::NAME, $notification)
            : null;
        if (\is_array($route)) {
            $route = $route[$channel] ?? null;
        }
        if ((\is_string($route) && $route !== '') || \is_int($route)) {
            return (string) $route;
        }

        throw new \LogicException(sprintf(
            'No %s recipient for %s. Add routeNotificationForNexusMessaging() to it (returning the address, '
            .'or one per channel: [\'%s\' => …]), call ->to(...) on the message, or use '
            .'Notification::route(\'%s\', $address).',
            $channel,
            $notifiable::class,
            $channel,
            self::NAME,
        ));
    }

    /**
     * Stable across queue retries — Laravel gives each notification an id
     * before queueing. A synchronous send (no id) gets a fresh key per send.
     */
    private static function idempotencyKey(object $notifiable, object $notification, int $index, string $channel, string $to): ?string
    {
        // get_object_vars() from here sees public properties only — never trips
        // over a private `$id` on someone's own class.
        $id = get_object_vars($notification)['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }

        return 'nxs-ntf-'.md5(implode('|', [$id, NexusRecipients::identify($notifiable), $index, $channel, $to]));
    }

    private static function wrongType(object $notification): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            '%s::toNexusMessaging() must return a %s, a list of them, or null.',
            $notification::class,
            NexusMessagingMessage::class,
        ));
    }
}
