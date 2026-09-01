<?php

declare(strict_types=1);

namespace Inverge\Nexus\Monolog;

use Inverge\Nexus\NexusClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * A Monolog handler that forwards your app's logs to Nexus Logs — and, when a
 * record carries a `\Throwable` in its context (as Laravel and Symfony do for
 * unhandled exceptions), reports it to Nexus Errors too.
 *
 * Log lines are buffered and delivered in one batch when the buffer fills or on
 * shutdown, so logging never adds a request per line. Pass a queue-backed
 * {@see NexusClient} to move delivery off the request entirely.
 *
 * Requires monolog/monolog ^3.
 */
final class NexusLogHandler extends AbstractProcessingHandler
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly NexusClient $nexus,
        private readonly bool $captureExceptions = true,
        private readonly int $flushAt = 50,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;

        // Unhandled exceptions arrive with the Throwable in context — capture
        // the full error separately from the log line.
        $throwable = $context['exception'] ?? null;
        if ($this->captureExceptions && $throwable instanceof \Throwable && $record->level->value >= Level::Error->value) {
            try {
                $this->nexus->errors()->captureException($throwable, [
                    'level' => $record->level->value >= Level::Critical->value ? 'fatal' : 'error',
                    'handled' => false,
                ]);
            } catch (\Throwable) {
                // never let telemetry break the app
            }
        }

        // Throwables don't serialise as JSON cleanly — replace with a summary.
        if ($throwable instanceof \Throwable) {
            $context['exception'] = $throwable::class . ': ' . $throwable->getMessage();
        }

        $this->buffer[] = array_filter([
            'level' => self::mapLevel($record->level),
            'message' => $record->message,
            'source' => $record->channel !== '' ? $record->channel : null,
            'context' => $context !== [] ? $context : null,
            'timestamp' => $record->datetime->format(\DateTimeInterface::ATOM),
        ], static fn ($value) => $value !== null);

        if (count($this->buffer) >= $this->flushAt) {
            $this->flush();
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    /** Deliver buffered log lines as one batch. */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        try {
            $this->nexus->logs()->batch($batch);
        } catch (\Throwable) {
            // never let telemetry break the app
        }
    }

    private static function mapLevel(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warn',
            Level::Error => 'error',
            Level::Critical, Level::Alert, Level::Emergency => 'fatal',
        };
    }
}
