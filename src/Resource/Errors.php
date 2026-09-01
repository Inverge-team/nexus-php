<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

/**
 * Error monitoring — report exceptions/messages from your backend.
 */
final class Errors extends AbstractResource
{
    /**
     * Capture an error by message.
     *
     * @param array{type?:string,level?:string,handled?:bool,fingerprint?:string,stack?:mixed,context?:array<string,mixed>,release?:string,url?:string,distinctId?:string,sessionKey?:string,deviceKey?:string,osType?:string,osVersion?:string,browser?:string,appVersion?:string} $options
     *
     * @return array<mixed>
     */
    public function capture(string $message, array $options = []): array
    {
        return $this->client->request('POST', '/partner/errors', $this->compact([
            'message' => $message,
            'type' => $options['type'] ?? null,
            'level' => $options['level'] ?? null,
            'handled' => $options['handled'] ?? null,
            'fingerprint' => $options['fingerprint'] ?? null,
            'stack' => $options['stack'] ?? null,
            'context' => $options['context'] ?? null,
            'release' => $options['release'] ?? null,
            'url' => $options['url'] ?? null,
            'distinctId' => $options['distinctId'] ?? null,
            'sessionKey' => $options['sessionKey'] ?? null,
            'deviceKey' => $options['deviceKey'] ?? null,
            'osType' => $options['osType'] ?? null,
            'osVersion' => $options['osVersion'] ?? null,
            'browser' => $options['browser'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
        ])) ?? [];
    }

    /**
     * Capture a PHP {@see \Throwable} — derives the type, message and a
     * structured stacktrace the console renders.
     *
     * @param array<string, mixed> $options overrides (e.g. distinctId, context, level)
     *
     * @return array<mixed>
     */
    public function captureException(\Throwable $exception, array $options = []): array
    {
        return $this->capture($exception->getMessage(), array_merge([
            'type' => $exception::class,
            'handled' => true,
            'stack' => self::stackFrom($exception),
        ], $options));
    }

    /** @return list<array{function:?string,filename:?string,lineno:?int}> */
    private static function stackFrom(\Throwable $exception): array
    {
        $frames = [[
            'function' => null,
            'filename' => $exception->getFile(),
            'lineno' => $exception->getLine(),
        ]];

        foreach ($exception->getTrace() as $frame) {
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $frames[] = [
                'function' => $function !== '' ? $function : null,
                'filename' => $frame['file'] ?? null,
                'lineno' => isset($frame['line']) ? (int) $frame['line'] : null,
            ];
        }

        return $frames;
    }
}
