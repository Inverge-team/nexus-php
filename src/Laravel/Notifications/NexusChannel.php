<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\NexusClient;

/**
 * Every Nexus channel at once. Put `NexusChannel::class` (or `'nexus'`) in
 * `via()` and implement whichever methods you need — each one present is sent:
 *
 * | Method                  | Product                                        |
 * |-------------------------|------------------------------------------------|
 * | `toNexusPush()`         | push notification ({@see NexusPushChannel})     |
 * | `toNexusLiveActivity()` | Live Activity ({@see NexusLiveActivityChannel}) |
 * | `toNexusRealtime()`     | realtime / events / logs / errors ({@see NexusRealtimeChannel}) |
 *
 * ```php
 * public function via(object $notifiable): array
 * {
 *     return [NexusChannel::class];
 * }
 *
 * public function toNexusPush(object $notifiable): NexusPushMessage { … }
 * public function toNexusLiveActivity(object $notifiable): NexusLiveActivityMessage { … }
 * ```
 *
 * Reliable as one queued job: each product is sent independently (one failing
 * doesn't stop the others), the job still fails so Laravel retries it, and the
 * products that already went out are remembered — a retry re-sends only what
 * failed. A product whose own channel is also in `via()` (e.g. `'nexus-push'`)
 * is left to that channel, so it's never sent twice.
 */
final class NexusChannel
{
    public const NAME = 'nexus';

    /** @var array<string, array{0: string, 1: object}> product => [dedicated channel name, channel] */
    private array $parts;

    public function __construct(
        NexusClient $nexus,
        private readonly ?NexusDeliveryLedger $ledger = null,
    ) {
        // Order: the push first (what the user notices), then the Live Activity, then realtime.
        $this->parts = [
            'push' => [NexusPushChannel::NAME, new NexusPushChannel($nexus)],
            'liveActivity' => [NexusLiveActivityChannel::NAME, new NexusLiveActivityChannel($nexus)],
            'realtime' => [NexusRealtimeChannel::NAME, new NexusRealtimeChannel($nexus)],
        ];
    }

    /**
     * @return array<string, mixed> each sent product's result, keyed push / liveActivity / realtime
     *
     * @throws \Throwable the first failure, after every product was attempted
     */
    public function send(object $notifiable, object $notification): array
    {
        $alsoVia = $this->dedicatedChannelsInVia($notifiable, $notification);
        $results = [];
        $failure = null;

        foreach ($this->parts as $product => [$channelName, $channel]) {
            if (\in_array($channelName, $alsoVia, true)) {
                continue; // its own channel handles it — don't send twice
            }
            $key = $this->ledgerKey($notifiable, $notification, $product);
            if ($key !== null && $this->ledger?->delivered($key)) {
                continue; // already delivered on an earlier attempt
            }
            try {
                $result = $channel->send($notifiable, $notification);
            } catch (\Throwable $e) {
                $failure ??= $e;
                continue;
            }
            if ($result !== null) {
                $results[$product] = $result;
                if ($key !== null) {
                    $this->ledger?->markDelivered($key);
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $results;
    }

    /**
     * Dedicated Nexus channels the notification also routes to.
     *
     * @return list<string>
     */
    private function dedicatedChannelsInVia(object $notifiable, object $notification): array
    {
        if (!method_exists($notification, 'via')) {
            return [];
        }
        try {
            $via = (array) $notification->via($notifiable);
        } catch (\Throwable) {
            return [];
        }
        $byClass = [
            NexusPushChannel::class => NexusPushChannel::NAME,
            NexusLiveActivityChannel::class => NexusLiveActivityChannel::NAME,
            NexusRealtimeChannel::class => NexusRealtimeChannel::NAME,
        ];
        $names = [];
        foreach ($via as $channel) {
            if (\is_string($channel)) {
                $names[] = $byClass[$channel] ?? $channel;
            }
        }

        return $names;
    }

    /**
     * Stable across queue retries: Laravel gives each notification an id before
     * queueing; the notifiable is identified by class + key (or its routes).
     */
    private function ledgerKey(object $notifiable, object $notification, string $product): ?string
    {
        // get_object_vars() from here sees public properties only — never trips
        // over a private `$id` / `$routes` on someone's own class.
        $id = get_object_vars($notification)['id'] ?? null;
        if ($this->ledger === null || !\is_string($id) || $id === '') {
            return null; // a synchronous send has no retries to guard
        }
        $public = get_object_vars($notifiable);
        if (method_exists($notifiable, 'getKey')) {
            $who = $notifiable::class.':'.$notifiable->getKey();
        } elseif (\array_key_exists('routes', $public)) { // AnonymousNotifiable (Notification::route())
            $who = 'route:'.md5((string) json_encode($public['routes']));
        } else {
            // Stable across a queue retry (the object is rebuilt from the payload).
            $who = $notifiable::class.':'.md5((string) json_encode($public));
        }

        return 'nexus:delivered:'.md5($id.'|'.$who).':'.$product;
    }
}
