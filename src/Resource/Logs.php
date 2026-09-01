<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Structured logging. Levels: trace, debug, info, warn, error, fatal.
 */
final class Logs extends AbstractResource
{
    /**
     * @param array{source?:string,context?:array<string,mixed>,timestamp?:string,distinctId?:string,sessionKey?:string,deviceKey?:string,release?:string,osType?:string,osVersion?:string,browser?:string,appVersion?:string} $options
     */
    public function log(string $level, string $message, array $options = []): int
    {
        $line = $this->compact([
            'level' => $level,
            'message' => $message,
            'source' => $options['source'] ?? null,
            'context' => $options['context'] ?? null,
            'timestamp' => $options['timestamp'] ?? null,
        ]);

        return $this->batch([$line], $options);
    }

    /** @param array<string, mixed> $options */
    public function trace(string $message, array $options = []): int
    {
        return $this->log('trace', $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function debug(string $message, array $options = []): int
    {
        return $this->log('debug', $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function info(string $message, array $options = []): int
    {
        return $this->log('info', $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function warn(string $message, array $options = []): int
    {
        return $this->log('warn', $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function error(string $message, array $options = []): int
    {
        return $this->log('error', $message, $options);
    }

    /** @param array<string, mixed> $options */
    public function fatal(string $message, array $options = []): int
    {
        return $this->log('fatal', $message, $options);
    }

    /**
     * Send a batch of log lines sharing one identity/context.
     *
     * @param list<array<string, mixed>> $logs
     * @param array<string, mixed>        $context
     */
    public function batch(array $logs, array $context = []): int
    {
        $body = $this->compact([
            'logs' => array_values($logs),
            'release' => $context['release'] ?? null,
            'distinctId' => $context['distinctId'] ?? null,
            'sessionKey' => $context['sessionKey'] ?? null,
            'deviceKey' => $context['deviceKey'] ?? null,
            'osType' => $context['osType'] ?? null,
            'osVersion' => $context['osVersion'] ?? null,
            'browser' => $context['browser'] ?? null,
            'appVersion' => $context['appVersion'] ?? null,
        ]);

        $result = $this->client->request('POST', '/partner/logs', $body);

        return (int) ($result['written'] ?? count($logs));
    }
}
