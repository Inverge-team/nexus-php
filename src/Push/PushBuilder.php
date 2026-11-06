<?php

declare(strict_types=1);

namespace Inverge\Nexus\Push;

use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Support\Conditionable;

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
 *
 * Branch without leaving the chain with `when()` / `unless()` (Laravel's), and
 * deliver later with `sendAt()`:
 *
 * ```php
 * $nexus->push()->notification()
 *     ->title($title)
 *     ->image($thumbnail)                    // null leaves it unset
 *     ->when($ids === [], fn ($p) => $p->toSegment('All Clients'), fn ($p) => $p->toUsers($ids))
 *     ->sendAt(new \DateTimeImmutable('tomorrow 09:00', new \DateTimeZone('Asia/Baghdad')))
 *     ->send();                              // → ['id' => .., 'status' => 'scheduled', 'scheduledAt' => ..]
 * ```
 */
final class PushBuilder
{
    use Conditionable;

    private ?string $title = null;
    private ?string $body = null;
    /** @var array<string, string> per-language titles, when title() got a map */
    private array $titles = [];
    /** @var array<string, string> per-language bodies, when body() got a map */
    private array $bodies = [];
    private ?string $defaultLanguage = null;
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
    private ?string $sendAt = null;
    private ?string $name = null;

    public function __construct(private readonly NexusClient $client)
    {
    }

    // ---- content ----

    /**
     * The title — a string, or one per language keyed by language code:
     * `['en' => 'Your order shipped', 'de' => 'Bestellung versandt']`. Each device gets the
     * title for its language; an empty or missing translation falls back to the
     * default language (see {@see defaultLanguage()}).
     *
     * @param string|array<string, string|null> $title
     */
    public function title(string|array $title): self
    {
        if (\is_array($title)) {
            $this->titles = self::translations($title);
            $this->title = null;
        } else {
            $this->title = $title;
            $this->titles = [];
        }

        return $this;
    }

    /**
     * The body — a string, or one per language like {@see title()}. `null` / `''`
     * leaves it unset, so an optional value can be passed straight in.
     *
     * @param string|array<string, string|null>|null $body
     */
    public function body(string|array|null $body): self
    {
        if (\is_array($body)) {
            $this->bodies = self::translations($body);
            $this->body = null;
        } else {
            $this->body = $body === '' ? null : $body;
            $this->bodies = [];
        }

        return $this;
    }

    /**
     * The language a device gets when its own has no translation. Defaults to
     * `en` when there is one, else the first language given.
     */
    public function defaultLanguage(?string $lang): self
    {
        $this->defaultLanguage = $lang === null || trim($lang) === '' ? null : trim($lang);

        return $this;
    }

    /** `null` / `''` leaves the image unset. */
    public function image(?string $url): self
    {
        $this->imageUrl = $url === '' ? null : $url;

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

    // ---- scheduling ----

    /**
     * Deliver at this time instead of now — a `DateTimeInterface`, epoch seconds,
     * or an ISO-8601 string with an offset (up to a year ahead; a past time goes
     * out within a minute). `send()` then returns the scheduled send. `null`
     * clears it, so `->sendAt($maybe)` sends now when there is no time.
     */
    public function sendAt(\DateTimeInterface|int|string|null $at): self
    {
        $this->sendAt = $at === null || $at === '' ? null : self::sendAtValue($at);

        return $this;
    }

    /** Label shown for a scheduled send in the console. */
    public function name(?string $label): self
    {
        $this->name = $label === '' ? null : $label;

        return $this;
    }

    /**
     * The `sendAt` wire value — always an absolute instant. An offset-less string
     * is refused rather than guessed: read in the server's zone it would fire hours off.
     *
     * @internal
     */
    public static function sendAtValue(\DateTimeInterface|int|string $at): string
    {
        if ($at instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
        }
        if (\is_int($at)) {
            return gmdate('Y-m-d\\TH:i:s\\Z', $at);
        }
        $at = trim($at);
        if (preg_match('/(Z|[+-]\\d{2}:?\\d{2})$/i', $at) !== 1) {
            throw new \InvalidArgumentException(sprintf('sendAt needs a timezone offset (e.g. "2026-11-07T09:00:00Z"): %s', $at));
        }

        return $at;
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
        // Empty ids are ignored, so toUsers([]) / toUser('') add no target.
        $this->distinctIds = array_values(array_unique([...$this->distinctIds, ...self::nonEmpty($distinctIds)]));

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
        $this->segmentIds = array_values(array_unique([...$this->segmentIds, ...self::nonEmpty($segmentIds)]));

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
     * Deliver the notification — or, with {@see sendAt()}, schedule it.
     *
     * @return array<mixed> { sent, failed, recipients } — or the scheduled send
     *                      { id, status, scheduledAt, sentAt, recipients, sent, failed }
     */
    public function send(): array
    {
        $title = $this->toArray()['title'] ?? '';
        if ($title === '') {
            throw new \InvalidArgumentException('A push notification requires a title.');
        }
        if (!$this->all && $this->distinctIds === [] && $this->segmentIds === [] && $this->filter === []) {
            throw new \InvalidArgumentException('A push notification requires a target: toUsers(), setSegments(), where(), or toAll().');
        }

        return $this->client->push()->sendRaw($this->toArray());
    }

    /**
     * Schedule it — shorthand for `->sendAt($at)->send()`.
     *
     * @return array<mixed> { id, status, scheduledAt, … }
     */
    public function schedule(\DateTimeInterface|int|string $at): array
    {
        return $this->sendAt($at)->send();
    }

    /**
     * The assembled request payload (without sending) — handy for testing.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        [$title, $body, $localizations, $defaultLanguage] = $this->content();

        return array_filter([
            'title' => $title,
            'body' => $body,
            'localizations' => $localizations,
            'defaultLanguage' => $defaultLanguage,
            'imageUrl' => $this->imageUrl,
            'data' => $this->data ?: null,
            'options' => $this->options ?: null,
            'distinctIds' => $this->distinctIds ?: null,
            'segmentIds' => $this->segmentIds ?: null,
            'filter' => $this->filter ?: null,
            'all' => $this->all ?: null,
            'sendAt' => $this->sendAt,
            'name' => $this->sendAt !== null ? $this->name : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Title/body for the wire: the base text (the default language's, when given
     * per language) plus one complete `{title, body}` per language — a language
     * missing one of the two takes the default language's.
     *
     * @return array{0: ?string, 1: ?string, 2: ?array<string, array{title: string, body?: string}>, 3: ?string}
     */
    private function content(): array
    {
        if ($this->titles === [] && $this->bodies === []) {
            return [$this->title, $this->body, null, null];
        }
        $langs = array_values(array_unique([...array_keys($this->titles), ...array_keys($this->bodies)]));
        $default = $this->defaultLanguage
            ?? (isset($this->titles['en']) || isset($this->bodies['en']) ? 'en' : $langs[0]);
        $title = $this->title ?? $this->titles[$default] ?? ($this->titles !== [] ? reset($this->titles) : null);
        $body = $this->body ?? $this->bodies[$default] ?? null;

        $localizations = [];
        foreach ($langs as $lang) {
            $t = $this->titles[$lang] ?? $title;
            if ($t === null) {
                continue;
            }
            $localizations[$lang] = array_filter(
                ['title' => $t, 'body' => $this->bodies[$lang] ?? $body],
                static fn ($v) => $v !== null,
            );
        }

        return [$title, $body, $localizations ?: null, $default];
    }

    /**
     * Keep the non-empty translations, keyed by trimmed language code.
     *
     * @param array<mixed> $map
     *
     * @return array<string, string>
     */
    private static function translations(array $map): array
    {
        $out = [];
        foreach ($map as $lang => $text) {
            $lang = trim((string) $lang);
            if ($lang !== '' && \is_string($text) && trim($text) !== '') {
                $out[$lang] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<string>
     */
    private static function nonEmpty(array $ids): array
    {
        return array_values(array_filter($ids, static fn ($id) => $id !== null && $id !== ''));
    }
}
