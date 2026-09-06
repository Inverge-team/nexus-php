<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

use Inverge\Nexus\LiveActivity\LiveActivityBuilder;

/**
 * Live Activities — a live, updating view of an in-progress event on the iOS
 * Lock Screen / Dynamic Island and as an Android live notification. This is the
 * classic server-side use case: your backend starts an activity when an order /
 * ride / match begins, updates it as the status changes, and ends it when done.
 *
 * Prefer the fluent builder:
 * `$nexus->liveActivities()->activity('DeliveryAttributes', 'order_42')->status('Preparing')->progress(20)->toUser('u_1')->start();`
 */
final class LiveActivities extends AbstractResource
{
    /**
     * Start a fluent Live Activity. Pass `$activityType` to `start()`; for
     * `update()` / `end()` it may be omitted.
     */
    public function activity(string $activityTypeOrId, ?string $activityId = null): LiveActivityBuilder
    {
        // activity($type, $id) for start; activity($id) for update/end.
        return $activityId === null
            ? new LiveActivityBuilder($this->client, $activityTypeOrId)
            : new LiveActivityBuilder($this->client, $activityId, $activityTypeOrId);
    }

    /**
     * Start a live activity. Omit `distinctIds` for a shared activity (many users
     * watching the same event); pass them to target specific users/orders.
     *
     * @param array<string, mixed> $contentState  the initial state (title/subtitle/body/status/progress …)
     * @param array{distinctIds?:list<string>,attributes?:array<string,mixed>,staleDate?:string,priority?:int} $options
     *
     * @return array<mixed> { ios, android } — devices reached
     */
    public function start(string $activityType, string $activityId, array $contentState, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/start', $this->compact([
            'activityType' => $activityType,
            'activityId' => $activityId,
            'contentState' => $contentState === [] ? new \stdClass() : $contentState,
            'distinctIds' => ($options['distinctIds'] ?? null) ?: null,
            'attributes' => ($options['attributes'] ?? null) ?: null,
            'staleDate' => $options['staleDate'] ?? null,
            'priority' => $options['priority'] ?? null,
        ])) ?? [];
    }

    /**
     * Push a new content state to every device on this activity. Use `priority`
     * 5 for routine (unmetered) updates, 10 for immediate (metered).
     *
     * @param array<string, mixed> $contentState
     * @param array{staleDate?:string,priority?:int} $options
     *
     * @return array<mixed> { ios, android }
     */
    public function update(string $activityId, array $contentState, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/'.rawurlencode($activityId).'/update', $this->compact([
            'contentState' => $contentState === [] ? new \stdClass() : $contentState,
            'staleDate' => $options['staleDate'] ?? null,
            'priority' => $options['priority'] ?? null,
        ])) ?? [];
    }

    /**
     * End the activity. Optionally pass a final `contentState` and a
     * `dismissalDate` (ISO-8601) controlling when iOS removes it.
     *
     * @param array{contentState?:array<string,mixed>,dismissalDate?:string} $options
     *
     * @return array<mixed> { ios, android }
     */
    public function end(string $activityId, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/'.rawurlencode($activityId).'/end', $this->compact([
            'contentState' => ($options['contentState'] ?? null) ?: null,
            'dismissalDate' => $options['dismissalDate'] ?? null,
        ])) ?? [];
    }

    /**
     * Register an iOS push-to-start token (normally the client SDK does this).
     *
     * @param array{distinctId?:string,deviceKey?:string} $options
     *
     * @return array<mixed>
     */
    public function registerPushToStartToken(string $activityType, string $token, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/push-token', $this->compact([
            'activityType' => $activityType,
            'token' => $token,
            'distinctId' => $options['distinctId'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
        ])) ?? [];
    }

    /**
     * Register a running activity instance's update token (client-side; exposed
     * for BYO integrations).
     *
     * @param array{platform?:string,updateToken?:string,distinctId?:string,contentState?:array<string,mixed>} $options
     *
     * @return array<mixed>
     */
    public function registerActivity(string $activityId, string $activityType, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/register', $this->compact([
            'activityId' => $activityId,
            'activityType' => $activityType,
            'platform' => $options['platform'] ?? 'ios',
            'updateToken' => $options['updateToken'] ?? null,
            'distinctId' => $options['distinctId'] ?? null,
            'contentState' => ($options['contentState'] ?? null) ?: null,
        ])) ?? [];
    }

    /**
     * Report a delivery receipt / click / failure for analytics.
     *
     * @param array{platform?:string} $options
     *
     * @return array<mixed>
     */
    public function event(string $activityId, string $type, array $options = []): array
    {
        return $this->client->request('POST', '/partner/live-activities/event', $this->compact([
            'activityId' => $activityId,
            'type' => $type,
            'platform' => $options['platform'] ?? null,
        ])) ?? [];
    }
}
