<?php

declare(strict_types=1);

namespace Inverge\Nexus\Push;

use Inverge\Nexus\NexusClient;

/**
 * Fluent builder for a transactional push. Compose content, rich per-platform
 * options and the audience, then `send()`.
 *
 * ```php
 * $nexus->push()->notification()
 *     ->title('Your order shipped')
 *     ->body('Track it in the app')
 *     ->data(['screen' => '/orders/42'])
 *     ->button('track', 'Track', ['url' => 'https://x/track'])
 *     ->iosBadge(1)
 *     ->androidVisibility('public')
 *     ->setSegments(['vip', 'active_7d'])   // or ->toUsers([...]) / ->toAll()
 *     ->send();                              // → ['sent' => .., 'failed' => .., 'recipients' => ..]
 * ```
 */
final class PushBuilder
{
    private ?string $title = null;
    private ?string $body = null;
    private ?string $imageUrl = null;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<string> */
    private array $distinctIds = [];
    /** @var list<string> */
    private array $segmentIds = [];
    /** @var array<string, mixed> */
    private array $filter = [];
    private bool $all = false;

    public function __construct(private readonly NexusClient $client)
    {
    }

    // ---- content ----

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function image(string $url): self
    {
        $this->imageUrl = $url;

        return $this;
    }

    /**
     * Custom key/values delivered to the app (merged with any prior data).
     *
     * @param array<string, mixed> $data
     */
    public function data(array $data): self
    {
        $this->data = $data + $this->data;

        return $this;
    }

    // ---- options ----

    /**
     * Add an action button. `$opts`: url, event, icon, style (primary|secondary|text).
     *
     * @param array{url?:string,event?:string,icon?:string,style?:string} $opts
     */
    public function button(string $id, string $text, array $opts = []): self
    {
        $this->options['buttons'] ??= [];
        $this->options['buttons'][] = array_filter(
            ['id' => $id, 'text' => $text] + $opts,
            static fn ($v) => $v !== null,
        );

        return $this;
    }

    public function iosBadge(int $count): self
    {
        return $this->option('iosBadge', $count);
    }

    public function iosRelevanceScore(float $score): self
    {
        return $this->option('iosRelevanceScore', $score);
    }

    public function iosInterruptionLevel(string $level): self
    {
        return $this->option('iosInterruptionLevel', $level);
    }

    public function iosSubtitle(string $subtitle): self
    {
        return $this->option('iosSubtitle', $subtitle);
    }

    /** Android lockscreen visibility: public | private | secret. */
    public function androidVisibility(string $visibility): self
    {
        return $this->option('androidVisibility', $visibility);
    }

    public function androidLargeIcon(string $url): self
    {
        return $this->option('androidLargeIcon', $url);
    }

    public function androidBigPicture(string $url): self
    {
        return $this->option('androidBigPicture', $url);
    }

    public function androidAccentColor(string $color): self
    {
        return $this->option('androidAccentColor', $color);
    }

    /** Set any option field directly (escape hatch). */
    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Merge multiple options at once.
     *
     * @param array<string, mixed> $options
     */
    public function options(array $options): self
    {
        $this->options = $options + $this->options;

        return $this;
    }

    // ---- targeting ----

    /**
     * Target specific users by distinctId.
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

    /**
     * Target saved segments (union). `setSegments` / `toSegments` are aliases.
     *
     * @param list<string> $segmentIds
     */
    public function setSegments(array $segmentIds): self
    {
        $this->segmentIds = array_values(array_unique([...$this->segmentIds, ...$segmentIds]));

        return $this;
    }

    /**
     * @param list<string> $segmentIds
     */
    public function toSegments(array $segmentIds): self
    {
        return $this->setSegments($segmentIds);
    }

    public function toSegment(string $segmentId): self
    {
        return $this->setSegments([$segmentId]);
    }

    /**
     * Target an ad-hoc device filter (platform, lang, osType, appVersion,
     * lastSeenDays, tags).
     *
     * @param array<string, mixed> $filter
     */
    public function where(array $filter): self
    {
        $this->filter = $filter + $this->filter;

        return $this;
    }

    /** Target every subscribed device (overrides other targets). Use with care. */
    public function toAll(): self
    {
        $this->all = true;

        return $this;
    }

    // ---- send ----

    /**
     * Deliver the notification.
     *
     * @return array<mixed> { sent, failed, recipients }
     */
    public function send(): array
    {
        if ($this->title === null || $this->title === '') {
            throw new \InvalidArgumentException('A push notification requires a title.');
        }
        if (!$this->all && $this->distinctIds === [] && $this->segmentIds === [] && $this->filter === []) {
            throw new \InvalidArgumentException('A push notification requires a target: toUsers(), setSegments(), where(), or toAll().');
        }

        return $this->client->push()->sendRaw(array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'imageUrl' => $this->imageUrl,
            'data' => $this->data ?: null,
            'options' => $this->options ?: null,
            'distinctIds' => $this->distinctIds ?: null,
            'segmentIds' => $this->segmentIds ?: null,
            'filter' => $this->filter ?: null,
            'all' => $this->all ?: null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * The assembled request payload (without sending) — handy for testing.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'imageUrl' => $this->imageUrl,
            'data' => $this->data ?: null,
            'options' => $this->options ?: null,
            'distinctIds' => $this->distinctIds ?: null,
            'segmentIds' => $this->segmentIds ?: null,
            'filter' => $this->filter ?: null,
            'all' => $this->all ?: null,
        ], static fn ($v) => $v !== null);
    }
}
