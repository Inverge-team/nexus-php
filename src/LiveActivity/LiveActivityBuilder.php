<?php

declare(strict_types=1);

namespace Inverge\Nexus\LiveActivity;

use Inverge\Nexus\NexusClient;

/**
 * Fluent builder for a Live Activity. Compose the data / content-state and
 * targeting, then `start()`, `update()`, or `end()`.
 *
 * Templated (recommended) — template-only setters (`step`, `etaAt`, `value`,
 * `canceled`, …) send `data`, merged server-side on update:
 *
 * ```php
 * $nexus->liveActivities()->template('order_tracking', 'order_42')
 *     ->step('pending')->etaAt(new DateTimeImmutable('+25 minutes'))
 *     ->values(['driver' => 'Ahmed'])->toUser('user_1')
 *     ->start();
 *
 * $nexus->liveActivities()->activity('order_42')->step('arrived')->update();
 * $nexus->liveActivities()->activity('order_42')->step('completed')->end();
 * ```
 *
 * Plain (your own iOS widget) — `progress()` and the texts on their own keep
 * sending a `contentState`:
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
    /** @var array<string, mixed> */
    private array $data = [];
    private ?string $lang = null;
    /** @var array{title:string,body?:string}|null */
    private ?array $alert = null;
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
        /** @var string|array<string, mixed>|null */
        private readonly string|array|null $template = null,
    ) {
    }

    /** Template mode = a template, or a template-only field (step / state / etaAt / values). */
    private function templated(): bool
    {
        if ($this->template !== null) {
            return true;
        }
        foreach (['step', 'state', 'etaAt', 'values'] as $k) {
            if (\array_key_exists($k, $this->data)) {
                return true;
            }
        }

        return false;
    }

    // ---- template data ----

    /** The current step (a step id or label of the template). */
    public function step(string $step): self
    {
        $this->data['step'] = $step;

        return $this;
    }

    /** Arrival time → a live countdown on the device (DateTime, unix seconds or ms). */
    public function etaAt(\DateTimeInterface|int|float $at): self
    {
        $this->data['etaAt'] = $at instanceof \DateTimeInterface
            ? $at->getTimestamp()
            : (int) round($at > 1e11 ? $at / 1000 : $at);

        return $this;
    }

    /** A fixed ETA text instead of a countdown, e.g. '8 min'. */
    public function eta(string $text): self
    {
        return $this->value('eta', $text);
    }

    /** One {{placeholder}} value. */
    public function value(string $key, string|int|float|bool $value): self
    {
        $this->data['values'][$key] = $value;

        return $this;
    }

    /** @param array<string, string|int|float|bool> $values */
    public function values(array $values): self
    {
        // Later values win; insertion order is kept (and keys never renumbered).
        $this->data['values'] = array_replace($this->data['values'] ?? [], $values);

        return $this;
    }

    /** Show the canceled state. */
    public function canceled(): self
    {
        $this->data['state'] = 'canceled';

        return $this;
    }

    /** Show the finished state. */
    public function done(): self
    {
        $this->data['state'] = 'done';

        return $this;
    }

    /**
     * Merge raw template data (step, state, progress, etaAt, values).
     *
     * @param array<string, mixed> $data
     */
    public function data(array $data): self
    {
        $values = $data['values'] ?? null;
        unset($data['values']);
        $this->data = $data + $this->data;
        if (\is_array($values) && $values !== []) {
            $this->values($values);
        }

        return $this;
    }

    /** Start only: force one language for every recipient (default: each device's own). */
    public function lang(string $lang): self
    {
        $this->lang = $lang;

        return $this;
    }

    /** Start only: the iOS alert shown with the start. */
    public function alert(string $title, ?string $body = null): self
    {
        $this->alert = $body === null ? ['title' => $title] : ['title' => $title, 'body' => $body];

        return $this;
    }

    // ---- content-state (templated: these override the template's texts) ----

    public function title(string $v): self
    {
        return $this->text('title', $v);
    }

    public function subtitle(string $v): self
    {
        return $this->text('subtitle', $v);
    }

    public function body(string $v): self
    {
        return $this->text('body', $v);
    }

    private function text(string $key, string $v): self
    {
        $this->data[$key] = $v;

        return $this->set($key, $v);
    }

    public function status(string $v): self
    {
        return $this->set('status', $v);
    }

    /** Progress, 0.0–1.0 (or 0–100). Templated: toward the next step / along the bar. */
    public function progress(int|float $v): self
    {
        $this->data['progress'] = $v;

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
        if ($this->template !== null) {
            return $this->client->liveActivities()->startTemplate($this->template, $this->activityId, $this->data, array_filter([
                'distinctIds' => $this->distinctIds ?: null,
                'lang' => $this->lang,
                'alert' => $this->alert,
                'attributes' => $this->attributes ?: null,
                'staleDate' => $this->staleDate,
                'priority' => $this->priority,
            ], static fn ($v) => $v !== null));
        }
        if ($this->activityType === null || $this->activityType === '') {
            throw new \InvalidArgumentException('start() requires a template — ->template($key, $id) — or an activity type — ->activity($type, $id).');
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
        if ($this->templated()) {
            return $this->client->liveActivities()->updateData($this->activityId, $this->data, array_filter([
                'staleDate' => $this->staleDate,
                'priority' => $this->priority,
            ], static fn ($v) => $v !== null));
        }

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
        $templated = $this->templated();

        return $this->client->liveActivities()->end($this->activityId, array_filter([
            'data' => $templated ? ($this->data ?: null) : null,
            'contentState' => $templated ? null : ($this->state ?: null),
            'dismissalDate' => $this->dismissalDate,
        ], static fn ($v) => $v !== null));
    }
}
