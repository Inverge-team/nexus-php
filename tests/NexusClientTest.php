<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\RoomMessage;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class NexusClientTest extends TestCase
{
    /** @return array{0: NexusClient, 1: FakeTransport} */
    private function make(ApiResponse ...$responses): array
    {
        $transport = new FakeTransport(...$responses);
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);

        return [$client, $transport];
    }

    public function testRealtimeEmitEncodesRoomAndAuthenticates(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, json_encode(['ok' => true, 'recipients' => 3])));

        $result = $nexus->realtime()->emit('orders:42', 'status', ['state' => 'shipped']);

        $this->assertSame(3, $result['recipients']);
        $req = $transport->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.example.test/partner/rooms/orders%3A42/emit', $req['url']);
        $this->assertSame('nxs_test', $req['headers']['x-api-key']);
        $this->assertSame(['status'], $req['json']['events']);
        $this->assertSame(['state' => 'shipped'], $req['json']['payload']);
    }

    public function testRealtimeBroadcastNormalisesEachMessage(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, json_encode(['ok' => true, 'count' => 2])));

        $nexus->realtime()->broadcast([
            ['room' => 'orders:1', 'event' => 'location', 'payload' => ['lat' => 1]],
            ['name' => 'orders:2', 'events' => ['location', 'eta'], 'payload' => ['lat' => 2]],
        ]);

        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/rooms/emit', $req['url']);
        $rooms = $req['json']['rooms'];
        $this->assertCount(2, $rooms);
        // room -> name, single event -> events[]
        $this->assertSame('orders:1', $rooms[0]['name']);
        $this->assertSame(['location'], $rooms[0]['events']);
        $this->assertSame(['lat' => 1], $rooms[0]['payload']);
        // multiple events preserved
        $this->assertSame('orders:2', $rooms[1]['name']);
        $this->assertSame(['location', 'eta'], $rooms[1]['events']);
    }

    public function testEmitAcceptsRoomMessageDto(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, json_encode(['ok' => true])));

        $nexus->realtime()->emit(new RoomMessage('orders:42', ['status', 'eta'], ['state' => 'shipped']));

        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/rooms/orders%3A42/emit', $req['url']);
        $this->assertSame(['status', 'eta'], $req['json']['events']);
        $this->assertSame(['state' => 'shipped'], $req['json']['payload']);
    }

    public function testBroadcastAcceptsRoomMessageDtos(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, json_encode(['ok' => true, 'count' => 2])));

        $nexus->realtime()->broadcast([
            new RoomMessage('orders:1', 'location', ['lat' => 1]),
            RoomMessage::make('orders:2', ['location', 'eta'], ['lat' => 2]),
        ]);

        $rooms = $transport->lastRequest()['json']['rooms'];
        $this->assertSame('orders:1', $rooms[0]['name']);
        $this->assertSame(['location'], $rooms[0]['events']);
        $this->assertSame(['location', 'eta'], $rooms[1]['events']);
    }

    public function testEmitToRoomsFansOutSameEvents(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, json_encode(['ok' => true, 'count' => 3])));

        $payload = ['lat' => 36.2, 'lng' => 43.9];
        $nexus->realtime()->emitToRooms(['orders:1', 'orders:2', 'orders:3'], 'location', $payload);

        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/rooms/emit', $req['url']);
        $rooms = $req['json']['rooms'];
        $this->assertCount(3, $rooms);
        foreach ($rooms as $i => $room) {
            $this->assertSame('orders:' . ($i + 1), $room['name']);
            $this->assertSame(['location'], $room['events']);
            $this->assertSame($payload, $room['payload']);
        }
    }

    public function testEventsCaptureBuildsBatch(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(202, json_encode(['written' => 1])));

        $written = $nexus->events()->capture('order_placed', ['total' => 42], ['distinctId' => 'u_1']);

        $this->assertSame(1, $written);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/events', $req['url']);
        $this->assertSame('order_placed', $req['json']['events'][0]['name']);
        $this->assertSame(['total' => 42], $req['json']['events'][0]['properties']);
        $this->assertSame('u_1', $req['json']['distinctId']);
    }

    public function testFlagsIsEnabled(): void
    {
        [$nexus] = $this->make(new ApiResponse(200, json_encode([
            'flags' => ['beta' => true, 'theme' => 'dark', 'off' => false],
            'payloads' => ['theme' => ['color' => '#000']],
        ])));

        $flags = $nexus->flags()->evaluate('u_1');
        $this->assertTrue($flags['flags']['beta']);

        [$nexus2] = $this->make(new ApiResponse(200, json_encode([
            'flags' => ['theme' => 'dark'],
            'payloads' => ['theme' => ['color' => '#000']],
        ])));
        $this->assertSame('dark', $nexus2->flags()->variant('theme', 'u_1'));
    }

    public function testCaptureExceptionBuildsStructuredStack(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(201, json_encode(['id' => 'e_1'])));

        $nexus->errors()->captureException(new \RuntimeException('boom'), ['distinctId' => 'u_9']);

        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/errors', $req['url']);
        $this->assertSame('boom', $req['json']['message']);
        $this->assertSame(\RuntimeException::class, $req['json']['type']);
        $this->assertTrue($req['json']['handled']);
        $this->assertSame('u_9', $req['json']['distinctId']);
        $this->assertIsArray($req['json']['stack']);
        $this->assertArrayHasKey('filename', $req['json']['stack'][0]);
    }

    public function testSurveysActiveAndRespond(): void
    {
        [$nexus, $transport] = $this->make(
            new ApiResponse(200, json_encode(['surveys' => [['id' => 's_1', 'name' => 'NPS']]])),
            new ApiResponse(202, json_encode(['id' => 'r_1', 'completed' => true])),
        );

        $surveys = $nexus->surveys()->active(['distinctId' => 'u_1', 'properties' => ['plan' => 'pro']]);
        $this->assertSame('s_1', $surveys[0]['id']);
        $activeReq = $transport->requests[0];
        $this->assertSame('https://api.example.test/partner/surveys/active', $activeReq['url']);
        $this->assertSame(['plan' => 'pro'], $activeReq['json']['properties']);

        $res = $nexus->surveys()->complete('s_1', ['q_1' => 9], ['distinctId' => 'u_1']);
        $this->assertTrue($res['completed']);
        $respondReq = $transport->requests[1];
        $this->assertSame('https://api.example.test/partner/surveys/responses', $respondReq['url']);
        $this->assertSame('s_1', $respondReq['json']['surveyId']);
        $this->assertSame(['q_1' => 9], $respondReq['json']['answers']);
        $this->assertTrue($respondReq['json']['completed']);
    }

    public function testSurveyDismissSendsAnswersAsObject(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(202, json_encode(['id' => 'r_2'])));

        $nexus->surveys()->dismiss('s_1', ['distinctId' => 'u_1']);

        $req = $transport->lastRequest();
        $this->assertTrue($req['json']['dismissed']);
        // Empty answers must be an object ({}), not [] — the API validates it.
        $this->assertInstanceOf(\stdClass::class, $req['json']['answers']);
    }

    public function testNonSuccessThrowsApiException(): void
    {
        [$nexus] = $this->make(new ApiResponse(400, json_encode([
            'error' => ['code' => 'validation_failed', 'message' => 'events_required'],
        ])));

        $this->expectException(ApiException::class);
        try {
            $nexus->events()->capture('x');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame('events_required', $e->getMessage());
            throw $e;
        }
    }

    public function testCompactDropsNullOptionalFields(): void
    {
        [$nexus, $transport] = $this->make(new ApiResponse(200, '{}'));

        $nexus->sessions()->identify('u_1'); // no email/name/traits

        $req = $transport->lastRequest();
        $this->assertSame(['distinctId' => 'u_1'], $req['json']);
    }
}
