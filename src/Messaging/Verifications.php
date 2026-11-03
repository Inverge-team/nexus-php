<?php

declare(strict_types=1);

namespace Inverge\Nexus\Messaging;

use Inverge\Nexus\Http\SyncDispatcher;

/**
 * One-time codes on WhatsApp first, then Telegram.
 *
 * ```php
 * $v = $nexus->messaging()->verifications()->start('+9647501234567');
 * if ($nexus->messaging()->verifications()->check($v['id'], $code)['valid']) { … }
 * ```
 */
final class Verifications
{
    private const BASE = '/partner/messaging/verifications';

    public function __construct(
        private readonly SyncDispatcher $dispatcher,
        private readonly \Closure $enabled,
    ) {
    }

    /**
     * Send a code to `$to` (E.164). Keep the returned `id` to check it.
     *
     * The code goes out on WhatsApp first and moves to Telegram when WhatsApp
     * refuses it or hasn't delivered it within `$fallbackAfter` seconds;
     * `$channels` sets another order. `$smsFallback` also tries SMS last, where
     * SMS is available.
     *
     * @param list<string>|null         $channels e.g. ['whatsapp', 'telegram']
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    public function start(
        string $to,
        ?array $channels = null,
        ?string $whatsappTemplate = null,
        ?string $whatsappLanguage = null,
        ?int $codeLength = null,
        ?int $ttl = null,
        bool $smsFallback = false,
        ?int $fallbackAfter = null,
        ?string $smsSender = null,
        ?string $smsTemplate = null,
        ?string $senderUsername = null,
        ?int $maxAttempts = null,
        ?array $metadata = null,
    ): ?array {
        if ($to === '') {
            throw new \InvalidArgumentException('verifications()->start() needs a phone number in E.164 form.');
        }

        return $this->call('POST', self::BASE, Messaging::compact([
            'to' => $to,
            'channels' => $channels === null ? null : array_values($channels),
            'whatsappTemplate' => $whatsappTemplate,
            'whatsappLanguage' => $whatsappLanguage,
            'codeLength' => $codeLength,
            'ttl' => $ttl,
            'fallback' => $smsFallback ? 'sms' : null,
            'fallbackAfter' => $fallbackAfter,
            'smsSender' => $smsSender,
            'smsTemplate' => $smsTemplate,
            'senderUsername' => $senderUsername,
            'maxAttempts' => $maxAttempts,
            'metadata' => $metadata === null ? null : (object) $metadata,
        ]));
    }

    /**
     * Check what the user typed: `valid` says whether it matched,
     * `attemptsRemaining` how many tries are left.
     *
     * @return array<string, mixed>|null
     */
    public function check(string $id, string $code): ?array
    {
        return $this->call('POST', self::BASE . '/' . rawurlencode($id) . '/check', ['code' => $code]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->call('GET', self::BASE . '/' . rawurlencode($id));
    }

    /** @return array<string, mixed>|null */
    public function cancel(string $id): ?array
    {
        // No body: an empty PHP array would encode as `[]`, not `{}`.
        return $this->call('POST', self::BASE . '/' . rawurlencode($id) . '/cancel');
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, ?array $json = null): ?array
    {
        if (!($this->enabled)()) {
            return null;
        }

        return $this->dispatcher->dispatch($method, $path, $json);
    }
}
