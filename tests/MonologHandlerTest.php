<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\Monolog\NexusLogHandler;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class MonologHandlerTest extends TestCase
{
    /** @param array<string, mixed> $context */
    private function record(Level $level, string $message, array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', $level, $message, $context, []);
    }

    private function client(FakeTransport $transport): NexusClient
    {
        return new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);
    }

    public function testBuffersLogsAndBatchesOnClose(): void
    {
        $transport = new FakeTransport(new ApiResponse(202, json_encode(['written' => 2])));
        $handler = new NexusLogHandler($this->client($transport));

        $handler->handle($this->record(Level::Info, 'hello'));
        $handler->handle($this->record(Level::Warning, 'careful'));
        $this->assertCount(0, $transport->requests, 'buffered, not sent yet');

        $handler->close();

        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/logs', $req['url']);
        $this->assertCount(2, $req['json']['logs']);
        $this->assertSame('info', $req['json']['logs'][0]['level']);
        $this->assertSame('warn', $req['json']['logs'][1]['level']); // Warning -> warn
        $this->assertSame('app', $req['json']['logs'][0]['source']);
    }

    public function testCapturesExceptionsToErrors(): void
    {
        $transport = new FakeTransport(
            new ApiResponse(201, json_encode(['id' => 'e_1'])), // errors capture
            new ApiResponse(202, json_encode(['written' => 1])), // logs batch on close
        );
        $handler = new NexusLogHandler($this->client($transport));

        $handler->handle($this->record(Level::Error, 'boom', ['exception' => new \RuntimeException('boom')]));
        $handler->close();

        $this->assertSame('https://api.example.test/partner/errors', $transport->requests[0]['url']);
        $this->assertSame(\RuntimeException::class, $transport->requests[0]['json']['type']);
        $this->assertFalse($transport->requests[0]['json']['handled']);

        // The throwable is replaced by a string in the log line (JSON-safe).
        $logs = $transport->lastRequest();
        $this->assertIsString($logs['json']['logs'][0]['context']['exception']);
    }

    public function testCaptureExceptionsCanBeDisabled(): void
    {
        $transport = new FakeTransport(new ApiResponse(202, '{}'));
        $handler = new NexusLogHandler($this->client($transport), captureExceptions: false);

        $handler->handle($this->record(Level::Error, 'boom', ['exception' => new \RuntimeException('x')]));
        $handler->close();

        $this->assertCount(1, $transport->requests, 'only the logs batch, no error capture');
        $this->assertSame('https://api.example.test/partner/logs', $transport->requests[0]['url']);
    }
}
