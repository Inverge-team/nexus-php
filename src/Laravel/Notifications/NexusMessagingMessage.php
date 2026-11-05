<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel\Notifications;

use Inverge\Nexus\Messaging\Messaging;

/**
 * The message a notification's `toNexusMessaging()` returns — one send on
 * WhatsApp, Telegram, SMS, Viber or any other messaging channel, with the same
 * fields as `$nexus->messaging()->send()`, without needing the client.
 *
 * ```php
 * public function toNexusMessaging(object $notifiable): NexusMessagingMessage
 * {
 *     return NexusMessagingMessage::whatsapp()
 *         ->template('order_shipped', 'en_US', ['order_id' => $this->order->number]);
 * }
 * ```
 *
 * Sent to the notifiable's `routeNotificationForNexusMessaging()` (a phone
 * number / handle, or one per channel: `['whatsapp' => '+964…', 'telegram' =>
 * '@alice']`) unless you call `to()`. Return a list of messages to send on
 * several channels at once.
 */
final class NexusMessagingMessage
{
    private ?string $to = null;
    private ?string $body = null;
    /** @var array<string, mixed>|null */
    private ?array $content = null;
    private ?string $sender = null;
    private ?string $template = null;
    private ?string $language = null;
    /** @var array<string, string|int>|null */
    private ?array $variables = null;
    private ?string $messageClass = null;
    private \DateTimeInterface|string|null $scheduledAt = null;
    private ?int $validityPeriodSec = null;
    /** @var array<string, mixed>|null */
    private ?array $metadata = null;
    private ?bool $trackLinks = null;
    private ?string $clientIp = null;
    private ?string $idempotencyKey = null;

    private function __construct(private readonly string $channel)
    {
    }

    /** A message on any channel by name — 'whatsapp', 'telegram', 'sms', 'viber', 'viber_bm', … */
    public static function create(string $channel, ?string $body = null): self
    {
        if ($channel === '') {
            throw new \InvalidArgumentException('A messaging notification needs a channel, e.g. "whatsapp".');
        }
        $m = new self($channel);
        $m->body = $body;

        return $m;
    }

    public static function whatsapp(?string $body = null): self
    {
        return self::create('whatsapp', $body);
    }

    public static function telegram(?string $body = null): self
    {
        return self::create('telegram', $body);
    }

    public static function sms(?string $body = null): self
    {
        return self::create('sms', $body);
    }

    public static function viber(?string $body = null): self
    {
        return self::create('viber', $body);
    }

    /** The recipient (phone number, Telegram handle / chat id, …) — overrides the notifiable's route. */
    public function to(string $to): self
    {
        $this->to = $to;

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Structured content, passed through as given — WhatsApp keys it by `kind`,
     * Telegram by `type`.
     *
     * @param array<string, mixed> $content
     */
    public function content(array $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function sender(string $sender): self
    {
        $this->sender = $sender;

        return $this;
    }

    /**
     * Send an approved template by its key.
     *
     * @param array<string, string|int> $variables the template's named variables
     */
    public function template(string $key, ?string $language = null, array $variables = []): self
    {
        $this->template = $key;
        $this->language = $language ?? $this->language;
        if ($variables !== []) {
            $this->variables($variables);
        }

        return $this;
    }

    public function language(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /** @param array<string, string|int> $variables merged into any set before */
    public function variables(array $variables): self
    {
        $this->variables = array_merge($this->variables ?? [], $variables);

        return $this;
    }

    public function messageClass(string $messageClass): self
    {
        $this->messageClass = $messageClass;

        return $this;
    }

    /** Deliver later (ISO-8601 string or a date). */
    public function scheduleAt(\DateTimeInterface|string $at): self
    {
        $this->scheduledAt = $at;

        return $this;
    }

    /** Give up on delivery after this many seconds. */
    public function validFor(int $seconds): self
    {
        $this->validityPeriodSec = $seconds;

        return $this;
    }

    /** @param array<string, mixed> $metadata echoed back on reads and webhooks; merged into any set before */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);

        return $this;
    }

    public function trackLinks(bool $track = true): self
    {
        $this->trackLinks = $track;

        return $this;
    }

    /** The end user's IP, for the per-IP abuse limit. */
    public function clientIp(string $ip): self
    {
        $this->clientIp = $ip;

        return $this;
    }

    /**
     * Your own idempotency key (e.g. an order id). Without one, a queued
     * notification gets a key that is stable across its retries.
     */
    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    public function channel(): string
    {
        return $this->channel;
    }

    /** The recipient set with `to()`, if any. */
    public function recipient(): ?string
    {
        return $this->to;
    }

    /**
     * Send it.
     *
     * @internal used by {@see NexusMessagingChannel}
     *
     * @param string      $to         the resolved recipient
     * @param string|null $defaultKey used when the message has no idempotency key of its own
     *
     * @return array<string, mixed>|null
     */
    public function sendWith(Messaging $messaging, string $to, ?string $defaultKey = null): ?array
    {
        return $messaging->send(
            channel: $this->channel,
            to: $to,
            body: $this->body,
            content: $this->content,
            sender: $this->sender,
            template: $this->template,
            language: $this->language,
            variables: $this->variables,
            messageClass: $this->messageClass,
            scheduledAt: $this->scheduledAt,
            validityPeriodSec: $this->validityPeriodSec,
            metadata: $this->metadata,
            trackLinks: $this->trackLinks,
            clientIp: $this->clientIp,
            idempotencyKey: $this->idempotencyKey ?? $defaultKey,
        );
    }
}
