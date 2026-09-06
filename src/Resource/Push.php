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
     *   - title (string, required)
     *   - body, imageUrl (string)
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
        return $this->sendRaw($this->compact([
            'distinctIds' => $distinctIds,
            'title' => $message['title'] ?? null,
            'body' => $message['body'] ?? null,
            'imageUrl' => $message['imageUrl'] ?? null,
            'data' => ($message['data'] ?? null) ?: null,
            'options' => ($message['options'] ?? null) ?: null,
        ]));
    }

    /**
     * Send a fully-assembled payload (title + targeting: distinctIds / segmentIds
     * / filter / all). Used by the builder; call it directly if you prefer.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed> { sent, failed, recipients }
     */
    public function sendRaw(array $payload): array
    {
        return $this->client->request('POST', '/partner/push/send', $payload) ?? [];
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
