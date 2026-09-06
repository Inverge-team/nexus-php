<?php

declare(strict_types=1);

namespace Inverge\Nexus\LiveActivity;

use Inverge\Nexus\NexusClient;

/**
 * Fluent builder for a Live Activity. Compose the content-state and targeting,
 * then `start()`, `update()`, or `end()`.
 *
 * ```php
 * $nexus->liveActivities()->activity('DeliveryAttributes', 'order_42')
 *     ->title('Order #42')->status('Preparing')->progress(20)
 *     ->toUsers(['user_1'])->priority(10)
 *     ->start();
 *
 * $nexus->liveActivities()->activity('order_42')
 *     ->status('On the way')->progress(70)->priority(5)->update();
 *
 * $nexus->liveActivities()->activity('order_42')->status('Delivered')->end();
 * ```
 */
final class LiveActivityBuilder
{
    /** @var array<string, mixed> */
    private array $state = [];
    /** @var list<string> */
    private array $distinctIds = [];
    /** @var array<string, mixed> */
    private array $attributes = [];
    private ?int $priority = null;
    private ?string $staleDate = null;
    private ?string $dismissalDate = null;

    public function __construct(
        private readonly NexusClient $client,
        private readonly string $activityId,
        private readonly ?string $activityType = null,
    ) {
    }

    // ---- content-state ----

    public function title(string $v): self
    {
        return $this->set('title', $v);
    }

    public function subtitle(string $v): self
    {
        return $this->set('subtitle', $v);
    }

    public function body(string $v): self
    {
        return $this->set('body', $v);
    }

    public function status(string $v): self
    {
        return $this->set('status', $v);
    }

    /** Progress, 0–100 (or 0.0–1.0 — both are accepted by the renderers). */
    public function progress(int|float $v): self
    {
        return $this->set('progress', $v);
    }

    /** Set any content-state field. */
    public function set(string $key, mixed $value): self
    {
        $this->state[$key] = $value;

        return $this;
    }

    /**
     * Merge multiple content-state fields.
     *
     * @param array<string, mixed> $state
     */
    public function state(array $state): self
    {
        $this->state = $state + $this->state;

        return $this;
    }

    // ---- targeting (start) ----

    /**
     * Target specific users (per-user/order activity). Omit for a shared activity.
     *
     * @param list<string> $distinctIds
     */
    public function toUsers(array $distinctIds): self
    {
        $this->distinctIds = array_values(array_unique([...$this->distinctIds, ...$distinctIds]));

        return $this;
    }

    public function toUser(string $distinctId): self
    {
        return $this->toUsers([$distinctId]);
    }

    /** A shared activity — everyone who registered (a match, a launch, an incident). */
    public function shared(): self
    {
        $this->distinctIds = [];

        return $this;
    }

    /**
     * Fixed attributes set at start (iOS ActivityKit).
     *
     * @param array<string, mixed> $attributes
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = $attributes + $this->attributes;

        return $this;
    }

    // ---- modifiers ----

    /** APNs priority: 5 = routine (unmetered), 10 = immediate (metered). */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /** ISO-8601 — mark the activity stale after this time. */
    public function staleAt(string $iso): self
    {
        $this->staleDate = $iso;

        return $this;
    }

    /** ISO-8601 — when iOS should remove the activity (end only). */
    public function dismissAt(string $iso): self
    {
        $this->dismissalDate = $iso;

        return $this;
    }

    // ---- terminal ----

    /**
     * Start the activity.
     *
     * @return array<mixed> { ios, android }
     */
    public function start(): array
    {
        if ($this->activityType === null || $this->activityType === '') {
            throw new \InvalidArgumentException('start() requires an activity type: ->activity($type, $id).');
        }

        return $this->client->liveActivities()->start($this->activityType, $this->activityId, $this->state, array_filter([
            'distinctIds' => $this->distinctIds ?: null,
            'attributes' => $this->attributes ?: null,
            'staleDate' => $this->staleDate,
            'priority' => $this->priority,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Push a new content-state.
     *
     * @return array<mixed> { ios, android }
     */
    public function update(): array
    {
        return $this->client->liveActivities()->update($this->activityId, $this->state, array_filter([
            'staleDate' => $this->staleDate,
            'priority' => $this->priority,
        ], static fn ($v) => $v !== null));
    }

    /**
     * End the activity (with an optional final content-state and dismissal time).
     *
     * @return array<mixed> { ios, android }
     */
    public function end(): array
    {
        return $this->client->liveActivities()->end($this->activityId, array_filter([
            'contentState' => $this->state ?: null,
            'dismissalDate' => $this->dismissalDate,
        ], static fn ($v) => $v !== null));
    }
}
