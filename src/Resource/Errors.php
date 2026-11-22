<?php

declare(strict_types=1);

namespace Inverge\Nexus\Resource;

use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\BillingSuspendedException;
use Inverge\Nexus\Exception\TransportException;

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
        if (self::isDeliveryFailure($exception)) {
            return [];
        }

        return $this->capture($exception->getMessage(), array_merge([
            'type' => $exception::class,
            'handled' => true,
            'stack' => self::stackFrom($exception),
        ], $options));
    }

    /**
     * Whether `$exception` (or anything it wraps) is the SDK failing to REACH
     * Nexus — billing suspension, rate limiting, an outage, the network. Those
     * are never reported to Nexus Errors: the report would travel the same
     * failing path, fail, and be reported again. A 4xx the API answered for a
     * bad request (validation, not found…) is still the app's bug and is reported.
     */
    public static function isDeliveryFailure(\Throwable $exception): bool
    {
        for ($e = $exception, $depth = 0; $e !== null && $depth < 10; $e = $e->getPrevious(), $depth++) {
            if ($e instanceof BillingSuspendedException || $e instanceof TransportException) {
                return true;
            }
            if ($e instanceof ApiException && ($e->errorCode === 'billing_suspended' || $e->status === 429 || $e->status >= 500)) {
                return true;
            }
        }

        return false;
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
