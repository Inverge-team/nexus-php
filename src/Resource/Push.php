<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

use Inverge\Nexus\Push\PushBuilder;

/**
 * Push notifications. Server-side you mainly send transactional notifications to
 * users or segments; campaigns, provider credentials, segments and templates are
 * managed in the Nexus console. Delivered via FCM / APNs / Web Push.
 *
 * Prefer the fluent builder for anything beyond a plain send:
 * `$nexus->push()->notification()->title('…')->setSegments(['vip'])->send();`
 */
final class Push extends AbstractResource
{
    /** Start a fluent push notification. */
    public function notification(): PushBuilder
    {
        return new PushBuilder($this->client);
    }

    /**
     * Send a transactional push to one or more users (by distinctId), delivered
     * across each user's registered devices.
     *
     * `$message`:
     *   - title (string, required) — or one per language: `['en' => …, 'ar' => …]`
     *   - body (string or per-language map), imageUrl (string)
     *   - defaultLanguage (string) — for devices whose language has no translation
     *   - data  (array<string,mixed>) — custom key/values delivered to the app
     *   - options (array) — rich per-platform options: buttons, androidLargeIcon,
     *     androidBigPicture, androidVisibility, iosBadge, iosRelevanceScore,
     *     iosInterruptionLevel, webIcon, … (see the console composer).
     *
     * @param list<string>         $distinctIds
     * @param array<string, mixed> $message
     *
     * @return array<mixed> { sent, failed }
     */
    public function send(array $distinctIds, array $message): array
    {
        return $this->sendRaw($this->messagePayload($distinctIds, $message));
    }

    /**
     * Deliver a transactional push to users later. `$sendAt` is a
     * `DateTimeInterface`, epoch seconds or an ISO-8601 string with an offset.
     * Keep the returned `id` to check on it ({@see scheduled()}) or cancel it.
     *
     * @param list<string>         $distinctIds
     * @param array<string, mixed> $message
     *
     * @return array<mixed> { id, status, scheduledAt, sentAt, recipients, sent, failed }
     */
    public function schedule(array $distinctIds, array $message, \DateTimeInterface|int|string $sendAt): array
    {
        return $this->sendRaw(
            $this->messagePayload($distinctIds, $message) + ['sendAt' => PushBuilder::sendAtValue($sendAt)],
        );
    }

    /**
     * The plain-call payload, built by the builder so per-language titles/bodies
     * expand the same way.
     *
     * @param list<string>         $distinctIds
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function messagePayload(array $distinctIds, array $message): array
    {
        $b = $this->notification()->toUsers($distinctIds);
        if (isset($message['title'])) {
            $b->title($message['title']);
        }
        $b->body($message['body'] ?? null)
            ->image($message['imageUrl'] ?? null)
            ->defaultLanguage($message['defaultLanguage'] ?? null);
        if (!empty($message['data'])) {
            $b->data($message['data']);
        }
        if (!empty($message['options'])) {
            $b->options($message['options']);
        }

        // distinctIds stays even when empty, as before (the API decides).
        return ['distinctIds' => $distinctIds] + $b->toArray();
    }

    /**
     * Send a fully-assembled payload (title + targeting: distinctIds / segmentIds
     * / filter / all, optional sendAt). Used by the builder; call it directly if you prefer.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed> { sent, failed, recipients } — or the scheduled send when `sendAt` is set
     */
    public function sendRaw(array $payload): array
    {
        return $this->client->request('POST', '/partner/push/send', $payload) ?? [];
    }

    /**
     * A scheduled send's status — and its sent / failed / recipients once it fired.
     * Needs a synchronous client (a queued dispatcher can't return a result).
     *
     * @return array<mixed> { id, status, scheduledAt, sentAt, recipients, sent, failed }
     */
    public function scheduled(string $id): array
    {
        return $this->client->request('GET', '/partner/push/scheduled/' . rawurlencode($id)) ?? [];
    }

    /**
     * Cancel a scheduled send before it fires. The API answers 409 once it has
     * started sending and 404 for an unknown id (an ApiException unless silent).
     *
     * @return array<mixed> the canceled send
     */
    public function cancelScheduled(string $id): array
    {
        return $this->client->request('POST', '/partner/push/scheduled/' . rawurlencode($id) . '/cancel', []) ?? [];
    }

    /**
     * Send the same notification to a single user.
     *
     * @param array<string, mixed> $message
     *
     * @return array<mixed>
     */
    public function sendToUser(string $distinctId, array $message): array
    {
        return $this->send([$distinctId], $message);
    }

    /**
     * Register (or refresh) a device token. Normally the client SDK does this;
     * exposed for server-driven / BYO-token flows.
     *
     * @param array{provider?:string,distinctId?:string,deviceKey?:string,appVersion?:string,osType?:string,osVersion?:string,lang?:string,tags?:array<string,string>} $options
     *
     * @return array<mixed>
     */
    public function registerToken(string $token, string $platform, array $options = []): array
    {
        return $this->client->request('POST', '/partner/push/register', $this->compact([
            'token' => $token,
            'platform' => $platform,
            'provider' => $options['provider'] ?? ($platform === 'web' ? 'webpush' : 'fcm'),
            'distinctId' => $options['distinctId'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
            'osType' => $options['osType'] ?? null,
            'osVersion' => $options['osVersion'] ?? null,
            'lang' => $options['lang'] ?? null,
            'tags' => ($options['tags'] ?? null) ?: null,
        ])) ?? [];
    }

    /**
     * Stop delivering to a device token (e.g. on logout / uninstall).
     *
     * @return array<mixed>
     */
    public function unregister(string $token): array
    {
        return $this->client->request('POST', '/partner/push/unregister', ['token' => $token]) ?? [];
    }
}
