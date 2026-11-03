<?php

declare(strict_types=1);

namespace Inverge\Nexus\Messaging;

use Inverge\Nexus\Http\SyncDispatcher;

/**
 * Messaging — send on WhatsApp, Telegram, SMS and the other channels.
 *
 * ```php
 * $nexus->messaging()->send('whatsapp', '+9647501234567',
 *     template: 'order_shipped', language: 'en_US', variables: ['order_id' => 'A-91']);
 * $nexus->messaging()->send('telegram', '@alice', body: 'Your order shipped');
 * ```
 *
 * Send-only: an API key sends messages, reads a sent message's status and
 * delivery timeline, uploads a file to attach, and runs OTP verifications.
 * Templates, numbers, senders, webhooks, campaigns and the inbox are managed in
 * the console. `content` is passed through as given — the API validates it per
 * channel (WhatsApp keys it by `kind`, Telegram by `type`).
 *
 * Failures throw {@see \Inverge\Nexus\Exception\ApiException} (`errorCode`
 * says why, e.g. `content_rejected`, `insufficient_balance`). When messaging is
 * switched off (`NEXUS_MESSAGING_ENABLED=false`) every call returns null.
 */
final class Messaging
{
    private const BASE = '/partner/messaging';

    private ?Verifications $verifications = null;

    public function __construct(
        private readonly SyncDispatcher $dispatcher,
        private readonly \Closure $enabled,
    ) {
    }

    /** OTP codes over Telegram, with SMS fallback. */
    public function verifications(): Verifications
    {
        return $this->verifications ??= new Verifications($this->dispatcher, $this->enabled);
    }

    /**
     * Send a message. Returns `['id' => …, 'status' => 'queued', 'price' => …]`;
     * delivery is asynchronous — read it back with {@see get()} / {@see events()}.
     *
     * Every send carries an `Idempotency-Key` — pass your own (e.g. an order id)
     * so a retry of the same send returns the first message instead of a second.
     *
     * @param array<string, mixed>|null      $content   structured content, validated per channel
     * @param array<string, string|int>|null $variables the template's named variables
     * @param array<string, mixed>|null      $metadata  echoed back on reads and webhooks
     *
     * @return array<string, mixed>|null
     */
    public function send(
        string $channel,
        string $to,
        ?string $body = null,
        ?array $content = null,
        ?string $sender = null,
        ?string $template = null,
        ?string $language = null,
        ?array $variables = null,
        ?string $messageClass = null,
        \DateTimeInterface|string|null $scheduledAt = null,
        ?int $validityPeriodSec = null,
        ?array $metadata = null,
        ?bool $trackLinks = null,
        ?string $clientIp = null,
        ?string $idempotencyKey = null,
    ): ?array {
        if ($channel === '' || $to === '') {
            throw new \InvalidArgumentException('messaging()->send() needs a channel and a recipient.');
        }
        if ($body === null && $content === null && $template === null) {
            throw new \InvalidArgumentException('messaging()->send() needs body, content or template.');
        }
        if ($scheduledAt instanceof \DateTimeInterface) {
            $scheduledAt = $scheduledAt->format(\DateTimeInterface::ATOM);
        }

        $payload = self::compact([
            'channel' => $channel,
            'to' => $to,
            'body' => $body,
            // `(object)` so an empty array still encodes as `{}`, never `[]`.
            'content' => $content === null ? null : (object) $content,
            'sender' => $sender,
            'templateKey' => $template,
            'templateLocale' => $language,
            'templateVariables' => $variables === null ? null : (object) array_map('strval', $variables),
            'messageClass' => $messageClass,
            'scheduledAt' => $scheduledAt,
            'validityPeriodSec' => $validityPeriodSec,
            'metadata' => $metadata === null ? null : (object) $metadata,
            'trackLinks' => $trackLinks,
            'clientIp' => $clientIp,
        ]);

        return $this->call('POST', self::BASE . '/messages', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? 'nxs-' . bin2hex(random_bytes(16)),
        ]);
    }

    /**
     * A sent message's current status, price and timestamps.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        return $this->call('GET', self::BASE . '/messages/' . rawurlencode($id));
    }

    /**
     * The delivery timeline: queued → submitted → sent → delivered.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $id): array
    {
        $out = $this->call('GET', self::BASE . '/messages/' . rawurlencode($id) . '/events');

        return is_array($out['events'] ?? null) ? array_values($out['events']) : [];
    }

    /**
     * Upload a file from disk for WhatsApp; send it as `['id' => $result['mediaId']]`.
     *
     * @return array<string, mixed>|null
     */
    public function uploadMedia(string $path, ?string $contentType = null, ?string $filename = null): ?array
    {
        return $this->uploadMediaBytes(self::read($path), $contentType ?? self::typeOf($path), $filename ?? basename($path));
    }

    /**
     * Upload bytes already in memory for WhatsApp.
     *
     * @return array<string, mixed>|null
     */
    public function uploadMediaBytes(string $bytes, string $contentType, ?string $filename = null): ?array
    {
        return $this->upload(self::BASE . '/media', $bytes, $contentType, $filename);
    }

    /**
     * Upload a file from disk for Telegram; send its `uploadId` (`tgf_…`) as the media.
     *
     * @return array<string, mixed>|null
     */
    public function uploadTelegramFile(string $path, ?string $contentType = null, ?string $filename = null): ?array
    {
        return $this->uploadTelegramFileBytes(self::read($path), $contentType ?? self::typeOf($path), $filename ?? basename($path));
    }

    /**
     * Upload bytes already in memory for Telegram.
     *
     * @return array<string, mixed>|null
     */
    public function uploadTelegramFileBytes(string $bytes, string $contentType, ?string $filename = null): ?array
    {
        return $this->upload(self::BASE . '/telegram/uploads', $bytes, $contentType, $filename);
    }

    /** @return array<string, mixed>|null */
    private function upload(string $path, string $bytes, string $contentType, ?string $filename): ?array
    {
        if (!($this->enabled)()) {
            return null;
        }
        if ($bytes === '') {
            throw new \InvalidArgumentException('The file is empty.');
        }
        if ($filename !== null && $filename !== '') {
            $path .= '?filename=' . rawurlencode($filename);
        }

        return $this->dispatcher->dispatchRaw('POST', $path, $bytes, $contentType);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $headers
     *
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, ?array $json = null, array $headers = []): ?array
    {
        if (!($this->enabled)()) {
            return null;
        }

        return $this->dispatcher->dispatch($method, $path, $json, $headers);
    }

    private static function read(string $path): string
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new \InvalidArgumentException("Cannot read {$path}.");
        }

        return $bytes;
    }

    /** By extension first — the same table every Nexus SDK uses — then by content. */
    private const TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'ogg' => 'audio/ogg', 'amr' => 'audio/amr', 'pdf' => 'application/pdf', 'txt' => 'text/plain',
        'csv' => 'text/csv', 'zip' => 'application/zip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private static function typeOf(string $path): string
    {
        $byExtension = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($byExtension !== null) {
            return $byExtension;
        }
        $type = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        if (!is_string($type) || $type === '' || $type === 'application/octet-stream') {
            throw new \InvalidArgumentException("Pass \$contentType (e.g. 'image/jpeg'); it could not be detected for {$path}.");
        }

        return $type;
    }

    /**
     * Drop nulls — the API rejects explicit nulls.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function compact(array $data): array
    {
        return array_filter($data, static fn ($v): bool => $v !== null);
    }
}
