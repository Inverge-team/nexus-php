<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\LiveActivity\LiveActivityBuilder;

/**
 * The Live Activity change a notification's `toNexusLiveActivity()` returns —
 * the same fluent API as `$nexus->liveActivities()`, without needing the client.
 * `create()` it for an activity id, then pick the lifecycle step — so each order
 * notification maps to one step:
 *
 * ```php
 * // OrderPlaced — start from a template designed in the Nexus console
 * return NexusLiveActivityMessage::create("order_{$this->order->id}")
 *     ->start('order_tracking')
 *     ->step('pending')->etaAt($this->order->eta)->values(['driver' => $this->order->driver_name]);
 *
 * // OrderStatusChanged — partial update, merged server-side
 * return NexusLiveActivityMessage::create("order_{$this->order->id}")->update()->step($this->order->status);
 *
 * // OrderDelivered — the final state, then dismissed
 * return NexusLiveActivityMessage::create("order_{$this->order->id}")->end()->step('completed');
 * ```
 *
 * A start goes to the notifiable (its `routeNotificationForNexusLiveActivity()` /
 * `routeNotificationForNexus()`) unless you call `toUser(s)()` or `shared()`.
 * Updates and ends reach every device on the activity by its id.
 *
 * @method self step(string $step)
 * @method self etaAt(\DateTimeInterface|int|float $at)
 * @method self eta(string $text)
 * @method self value(string $key, string|int|float|bool $value)
 * @method self values(array<string, string|int|float|bool> $values)
 * @method self canceled()
 * @method self done()
 * @method self data(array<string, mixed> $data)
 * @method self lang(string $lang)
 * @method self alert(string $title, ?string $body = null)
 * @method self title(string $v)
 * @method self subtitle(string $v)
 * @method self body(string $v)
 * @method self status(string $v)
 * @method self progress(int|float $v)
 * @method self set(string $key, mixed $value)
 * @method self state(array<string, mixed> $state)
 * @method self toUsers(list<string> $distinctIds)
 * @method self toUser(string $distinctId)
 * @method self shared()
 * @method self attributes(array<string, mixed> $attributes)
 * @method self priority(int $priority)
 * @method self staleAt(string $iso)
 * @method self dismissAt(string $iso)
 */
final class NexusLiveActivityMessage
{
    public const START = 'start';
    public const UPDATE = 'update';
    public const END = 'end';

    private const TARGETING = ['toUsers', 'toUser', 'shared'];
    private const TERMINAL = ['start', 'update', 'end'];

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $calls = [];
    private bool $targeted = false;
    private ?string $action = null;
    /** @var string|array<string, mixed>|null */
    private string|array|null $template = null;
    private ?string $activityType = null;

    private function __construct(private readonly string $activityId)
    {
    }

    /** A message for the activity with this id (yours — e.g. "order_42"). */
    public static function create(string $activityId): self
    {
        if ($activityId === '') {
            throw new \InvalidArgumentException('A Live Activity needs an id, e.g. "order_42".');
        }

        return new self($activityId);
    }

    /**
     * Start it from a template — a template key from the Nexus console (e.g.
     * 'order_tracking') or an inline design.
     *
     * @param string|array<string, mixed> $template
     */
    public function start(string|array $template): self
    {
        $this->action = self::START;
        $this->template = $template;
        $this->activityType = null;

        return $this;
    }

    /** Start a plain activity for your own iOS widget (its `ActivityAttributes` type). */
    public function startActivity(string $activityType): self
    {
        $this->action = self::START;
        $this->activityType = $activityType;
        $this->template = null;

        return $this;
    }

    /** Update it — templated: only what changed, merged server-side. */
    public function update(): self
    {
        $this->action = self::UPDATE;

        return $this;
    }

    /** End it, optionally with its final state (e.g. `->step('completed')`). */
    public function end(): self
    {
        $this->action = self::END;

        return $this;
    }

    /** @param list<mixed> $args */
    public function __call(string $method, array $args): self
    {
        if (!method_exists(LiveActivityBuilder::class, $method) || \in_array($method, self::TERMINAL, true)) {
            throw new \BadMethodCallException(sprintf('%s::%s() does not exist.', self::class, $method));
        }
        $this->calls[] = [$method, $args];
        $this->targeted = $this->targeted || \in_array($method, self::TARGETING, true);

        return $this;
    }

    public function action(): string
    {
        if ($this->action === null) {
            throw new \LogicException(sprintf(
                'Pick the Live Activity step for "%s": ->start($template), ->update() or ->end().', $this->activityId,
            ));
        }

        return $this->action;
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    /** Whether the message picked its own audience (start only; otherwise the notifiable). */
    public function isTargeted(): bool
    {
        return $this->targeted;
    }

    /**
     * Replay onto a real builder and run the lifecycle step.
     *
     * @internal used by {@see NexusLiveActivityChannel}
     *
     * @param list<string> $recipients applied to a start when the message isn't targeted
     *
     * @return array<mixed>
     */
    public function sendWith(\Inverge\Nexus\Resource\LiveActivities $resource, array $recipients): array
    {
        $builder = match (true) {
            $this->template !== null => $resource->template($this->template, $this->activityId),
            $this->activityType !== null => $resource->activity($this->activityType, $this->activityId),
            default => $resource->activity($this->activityId),
        };
        foreach ($this->calls as [$method, $args]) {
            $builder->{$method}(...$args);
        }
        if ($this->action() === self::START && !$this->targeted && $recipients !== []) {
            $builder->toUsers($recipients);
        }

        return match ($this->action()) {
            self::START => $builder->start(),
            self::UPDATE => $builder->update(),
            default => $builder->end(),
        };
    }
}
