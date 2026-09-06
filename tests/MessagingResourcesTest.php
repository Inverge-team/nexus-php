<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MessagingResourcesTest extends TestCase
{
    /**
     * @param ApiResponse ...$responses
     *
     * @return array{0: NexusClient, 1: FakeTransport}
     */
    private function make(ApiResponse ...$responses): array
    {
        $transport = new FakeTransport(...$responses);
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);

        return [$client, $transport];
    }

    public function testPushSendToUsers(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"sent":2,"failed":0}'));

        $result = $client->push()->send(['u_1', 'u_2'], [
            'title' => 'Order shipped',
            'body' => 'On its way',
            'data' => ['screen' => '/orders/42'],
            'options' => ['iosBadge' => 1, 'buttons' => [['id' => 'view', 'text' => 'View', 'action' => 'url', 'url' => 'https://x']]],
        ]);

        $this->assertSame(2, $result['sent']);
        $req = $transport->lastRequest();
        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://api.example.test/partner/push/send', $req['url']);
        $this->assertSame('nxs_test', $req['headers']['x-api-key']);
        $this->assertSame(['u_1', 'u_2'], $req['json']['distinctIds']);
        $this->assertSame('Order shipped', $req['json']['title']);
        $this->assertSame(['screen' => '/orders/42'], $req['json']['data']);
        $this->assertSame(1, $req['json']['options']['iosBadge']);
    }

    public function testPushSendOmitsNullFields(): void
    {
        [$client, $transport] = $this->make();
        $client->push()->sendToUser('u_1', ['title' => 'Hi']);

        $json = $transport->lastRequest()['json'];
        $this->assertSame(['u_1'], $json['distinctIds']);
        $this->assertSame('Hi', $json['title']);
        $this->assertArrayNotHasKey('body', $json);
        $this->assertArrayNotHasKey('options', $json);
    }

    public function testInAppActiveReturnsMessages(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"messages":[{"id":"m1"}]}'));

        $messages = $client->inApp()->active(['distinctId' => 'u_1']);

        $this->assertSame('m1', $messages[0]['id']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/inapp/active', $req['url']);
        $this->assertSame('u_1', $req['json']['distinctId']);
    }

    public function testInAppClickTracksButton(): void
    {
        [$client, $transport] = $this->make();
        $client->inApp()->click('m1', 'cta', ['distinctId' => 'u_1']);

        $json = $transport->lastRequest()['json'];
        $this->assertSame('https://api.example.test/partner/inapp/event', $transport->lastRequest()['url']);
        $this->assertSame('m1', $json['messageId']);
        $this->assertSame('click', $json['type']);
        $this->assertSame('cta', $json['buttonId']);
        $this->assertSame('u_1', $json['distinctId']);
    }

    public function testLiveActivityStart(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"ios":1,"android":0}'));

        $result = $client->liveActivities()->start('DeliveryAttributes', 'order_42', [
            'title' => 'Order #42', 'status' => 'Preparing', 'progress' => 20,
        ], ['distinctIds' => ['u_1'], 'priority' => 10]);

        $this->assertSame(1, $result['ios']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/start', $req['url']);
        $this->assertSame('DeliveryAttributes', $req['json']['activityType']);
        $this->assertSame('order_42', $req['json']['activityId']);
        $this->assertSame('Preparing', $req['json']['contentState']['status']);
        $this->assertSame(['u_1'], $req['json']['distinctIds']);
        $this->assertSame(10, $req['json']['priority']);
    }

    public function testLiveActivityUpdateAndEndEncodeActivityId(): void
    {
        [$client, $transport] = $this->make();

        $client->liveActivities()->update('order/42', ['status' => 'On the way']);
        $this->assertSame('https://api.example.test/partner/live-activities/order%2F42/update', $transport->lastRequest()['url']);

        $client->liveActivities()->end('order/42', ['dismissalDate' => '2026-09-06T20:00:00Z']);
        $end = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/order%2F42/end', $end['url']);
        $this->assertSame('2026-09-06T20:00:00Z', $end['json']['dismissalDate']);
    }

    public function testLiveActivityEmptyContentStateSerialisesAsObject(): void
    {
        [$client, $transport] = $this->make();
        $client->liveActivities()->update('a1', []);

        // Must be an object ({}), never [] — the API validates it.
        $this->assertInstanceOf(\stdClass::class, $transport->lastRequest()['json']['contentState']);
    }
}
