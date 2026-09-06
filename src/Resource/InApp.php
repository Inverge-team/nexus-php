<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * In-app messages — OneSignal-style modals & banners shown inside your app.
 * Messages are composed in the console; this resource fetches the ones a user is
 * eligible to see (for server-driven / headless UIs) and records impressions and
 * clicks. Triggers + frequency caps are applied by the client SDK.
 */
final class InApp extends AbstractResource
{
    /**
     * The active in-app messages for the tenant (the SDK evaluates triggers +
     * frequency locally).
     *
     * @param array{distinctId?:string,deviceKey?:string} $options
     *
     * @return array<mixed> list of messages
     */
    public function active(array $options = []): array
    {
        $result = $this->client->request('POST', '/partner/inapp/active', $this->compact([
            'distinctId' => $options['distinctId'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
        ]));

        return $result['messages'] ?? [];
    }

    /**
     * Record an in-app message event: `impression`, `click`, or `dismiss`.
     *
     * @param array{buttonId?:string,distinctId?:string} $options
     *
     * @return array<mixed>
     */
    public function trackEvent(string $messageId, string $type, array $options = []): array
    {
        return $this->client->request('POST', '/partner/inapp/event', $this->compact([
            'messageId' => $messageId,
            'type' => $type,
            'buttonId' => $options['buttonId'] ?? null,
            'distinctId' => $options['distinctId'] ?? null,
        ])) ?? [];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function impression(string $messageId, array $options = []): array
    {
        return $this->trackEvent($messageId, 'impression', $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function click(string $messageId, string $buttonId, array $options = []): array
    {
        return $this->trackEvent($messageId, 'click', ['buttonId' => $buttonId] + $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function dismiss(string $messageId, array $options = []): array
    {
        return $this->trackEvent($messageId, 'dismiss', $options);
    }
}
