<?php

declare(strict_types=1);

namespace Inverge\Nexus\Telescope;

/**
 * One unit of work being recorded — a request, job, command or scheduled task.
 *
 * @internal
 */
final class Batch
{
    /** @var array<string, array<string, mixed>> entries keyed by id */
    public array $entries = [];

    /** @var list<array{id:string,at:float}> open spans, innermost last */
    public array $spans = [];

    /** @var list<string> */
    public array $tags = [];

    /** @var array<string, mixed> */
    public array $user = [];

    public ?string $name = null;
    public ?string $status = null;
    public ?string $sessionKey = null;
    public ?string $deviceKey = null;
    public float $durationMs = 0.0;
    public int $dropped = 0;
    public bool $failed = false;

    /** @param array<string, mixed> $content */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $traceId,
        public readonly ?string $parentSpanId,
        public readonly float $startedAt,
        public array $content = [],
    ) {
    }

    /** The span new entries nest under: the innermost open span, else the batch itself. */
    public function currentSpan(): string
    {
        return $this->spans === [] ? $this->id : $this->spans[\count($this->spans) - 1]['id'];
    }

    /** Whether the work itself failed (5xx, failed job, non-zero exit). */
    public function isFailure(): bool
    {
        $s = $this->status ?? (isset($this->content['responseStatus']) ? (string) $this->content['responseStatus'] : null);
        if ($s === null) {
            return false;
        }

        return match ($this->type) {
            'request' => (int) $s >= 500,
            'job' => $s === 'failed',
            'command', 'schedule' => is_numeric($s) && (int) $s !== 0,
            default => false,
        };
    }

    /** @return array<string, mixed> the wire shape of `POST /partner/telescope` */
    public function toArray(): array
    {
        $content = $this->content;
        if ($this->dropped > 0) {
            $content['droppedEntries'] = $this->dropped;
        }
        $out = [
            'id' => $this->id,
            'type' => $this->type,
            'traceId' => $this->traceId,
            'startedAt' => round($this->startedAt * 1000, 3),
            'durationMs' => round($this->durationMs, 3),
            'content' => $content,
            'tags' => array_values(array_unique($this->tags)),
            'entries' => array_values($this->entries),
        ];
        if ($this->parentSpanId !== null) {
            $out['parentSpanId'] = $this->parentSpanId;
        }
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        if ($this->user !== []) {
            $out['user'] = $this->user;
        }
        if ($this->sessionKey !== null) {
            $out['sessionKey'] = $this->sessionKey;
        }
        if ($this->deviceKey !== null) {
            $out['deviceKey'] = $this->deviceKey;
        }

        return $out;
    }
}
