<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\Push\PushBuilder;
use Inverge\Nexus\Support\Conditionable;

/**
 * The push a notification's `toNexusPush()` returns — the same fluent API as
 * `$nexus->push()->notification()`, without needing the client. Sent by
 * {@see NexusPushChannel} to the notifiable unless you target it explicitly.
 *
 * ```php
 * public function toNexusPush(object $notifiable): NexusPushMessage
 * {
 *     return NexusPushMessage::create('Your order shipped', 'Track it in the app')
 *         ->data(['screen' => "/orders/{$this->order->id}"])
 *         ->button('track', 'Track')
 *         ->iosBadge(1)
 *         ->when($this->order->eta, fn ($m, $eta) => $m->sendAt($eta->subHour()));
 * }
 * ```
 *
 * `when()` / `unless()` run now, on the message — the calls the callback makes
 * are recorded like any other.
 *
 * @method self title(string|array<string, string|null> $title)
 * @method self body(string|array<string, string|null>|null $body)
 * @method self defaultLanguage(?string $lang)
 * @method self image(?string $url)
 * @method self data(array<string, mixed> $data)
 * @method self button(string $id, string $text, array<string, mixed> $opts = [])
 * @method self iosBadge(int $count)
 * @method self iosRelevanceScore(float $score)
 * @method self iosInterruptionLevel(string $level)
 * @method self iosSubtitle(string $subtitle)
 * @method self androidVisibility(string $visibility)
 * @method self androidLargeIcon(string $url)
 * @method self androidBigPicture(string $url)
 * @method self androidAccentColor(string $color)
 * @method self option(string $key, mixed $value)
 * @method self options(array<string, mixed> $options)
 * @method self toUsers(list<string> $distinctIds)
 * @method self toUser(string $distinctId)
 * @method self setSegments(list<string> $segmentIds)
 * @method self toSegments(list<string> $segmentIds)
 * @method self toSegment(string $segmentId)
 * @method self where(array<string, mixed> $filter)
 * @method self toAll()
 * @method self sendAt(\DateTimeInterface|int|string|null $at)
 * @method self name(?string $label)
 */
final class NexusPushMessage
{
    use Conditionable;

    /** Calls that choose the audience — when none is made, the notifiable is the target. */
    private const TARGETING = ['toUsers', 'toUser', 'setSegments', 'toSegments', 'toSegment', 'where', 'toAll'];

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $calls = [];
    private bool $targeted = false;

    /**
     * @param string|array<string, string|null>|null $title a string, or one per language (`['en' => …, 'ar' => …]`)
     * @param string|array<string, string|null>|null $body  likewise
     */
    public static function create(string|array|null $title = null, string|array|null $body = null): self
    {
        $m = new self();
        if ($title !== null) {
            $m->title($title);
        }
        if ($body !== null) {
            $m->body($body);
        }

        return $m;
    }

    /** @param list<mixed> $args */
    public function __call(string $method, array $args): self
    {
        if (!method_exists(PushBuilder::class, $method) || \in_array($method, ['send', 'schedule', 'toArray'], true)) {
            throw new \BadMethodCallException(sprintf('%s::%s() does not exist.', self::class, $method));
        }
        $this->calls[] = [$method, $args];
        $this->targeted = $this->targeted || \in_array($method, self::TARGETING, true);

        return $this;
    }

    /** Whether the message picked its own audience (otherwise the notifiable is used). */
    public function isTargeted(): bool
    {
        return $this->targeted;
    }

    /**
     * Replay onto a real builder.
     *
     * @internal used by {@see NexusPushChannel}
     */
    public function applyTo(PushBuilder $builder): PushBuilder
    {
        foreach ($this->calls as [$method, $args]) {
            $builder->{$method}(...$args);
        }

        return $builder;
    }
}
