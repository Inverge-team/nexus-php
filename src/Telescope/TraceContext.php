<?php

declare(strict_types=1);

namespace Inverge\Nexus\Telescope;

/**
 * W3C Trace Context (`traceparent: 00-<32 hex trace>-<16 hex span>-<flags>`).
 * Interoperates with OpenTelemetry, so a PHP service and an OTel-instrumented
 * Go/Java/Node service show up in the same Nexus trace.
 */
final class TraceContext
{
    /** @return array{traceId:string,spanId:string}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }
        if (!preg_match('/^\s*([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})\s*$/i', $header, $m)) {
            return null;
        }
        if ($m[2] === str_repeat('0', 32) || $m[3] === str_repeat('0', 16)) {
            return null;
        }

        return ['traceId' => strtolower($m[2]), 'spanId' => strtolower($m[3])];
    }

    public static function format(string $traceId, string $spanId): string
    {
        $trace = preg_match('/^[0-9a-f]{32}$/', $traceId) ? $traceId : substr(str_pad(preg_replace('/[^0-9a-f]/', '', strtolower($traceId)) ?? '', 32, '0', STR_PAD_LEFT), -32);
        $span = preg_match('/^[0-9a-f]{16}$/', $spanId) ? $spanId : substr(hash('sha256', $spanId), 0, 16);

        return "00-{$trace}-{$span}-01";
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
