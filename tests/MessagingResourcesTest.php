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

    public function testPushBuilderTargetsSegments(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"sent":5,"failed":0,"recipients":5}'));

        $result = $client->push()->notification()
            ->title('Flash sale')
            ->body('50% off today')
            ->data(['screen' => '/sale'])
            ->button('shop', 'Shop now', ['url' => 'https://x/sale'])
            ->iosBadge(1)
            ->androidVisibility('public')
            ->setSegments(['vip', 'active_7d'])
            ->toUsers(['u_9'])
            ->send();

        $this->assertSame(5, $result['recipients']);
        $json = $transport->lastRequest()['json'];
        $this->assertSame('https://api.example.test/partner/push/send', $transport->lastRequest()['url']);
        $this->assertSame('Flash sale', $json['title']);
        $this->assertSame(['vip', 'active_7d'], $json['segmentIds']);
        $this->assertSame(['u_9'], $json['distinctIds']);
        $this->assertSame('shop', $json['options']['buttons'][0]['id']);
        $this->assertSame('public', $json['options']['androidVisibility']);
        $this->assertSame(1, $json['options']['iosBadge']);
    }

    public function testPushBuilderToAllAndWhereFilter(): void
    {
        [$client, $transport] = $this->make();
        $client->push()->notification()->title('Hi')->where(['platform' => 'ios'])->send();
        $this->assertSame(['platform' => 'ios'], $transport->lastRequest()['json']['filter']);

        [$client2, $transport2] = $this->make();
        $client2->push()->notification()->title('Everyone')->toAll()->send();
        $this->assertTrue($transport2->lastRequest()['json']['all']);
    }

    public function testPushBuilderRequiresTitleAndTarget(): void
    {
        [$client] = $this->make();

        $this->expectException(\InvalidArgumentException::class);
        $client->push()->notification()->toUsers(['u_1'])->send(); // no title
    }

    public function testPushBuilderRequiresTarget(): void
    {
        [$client] = $this->make();

        $this->expectException(\InvalidArgumentException::class);
        $client->push()->notification()->title('Hi')->send(); // no target
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

    public function testLiveActivityBuilderStart(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"ios":1,"android":1}'));

        $result = $client->liveActivities()->activity('DeliveryAttributes', 'order_42')
            ->title('Order #42')->status('Preparing')->progress(20)->set('eta', 15)
            ->toUser('u_1')->priority(10)
            ->start();

        $this->assertSame(1, $result['ios']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/start', $req['url']);
        $this->assertSame('DeliveryAttributes', $req['json']['activityType']);
        $this->assertSame('order_42', $req['json']['activityId']);
        $this->assertSame('Preparing', $req['json']['contentState']['status']);
        $this->assertSame(15, $req['json']['contentState']['eta']);
        $this->assertSame(['u_1'], $req['json']['distinctIds']);
        $this->assertSame(10, $req['json']['priority']);
    }

    public function testLiveActivityBuilderUpdateAndEnd(): void
    {
        [$client, $transport] = $this->make();

        $client->liveActivities()->activity('order_42')->status('On the way')->progress(70)->priority(5)->update();
        $u = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/order_42/update', $u['url']);
        $this->assertSame('On the way', $u['json']['contentState']['status']);
        $this->assertSame(5, $u['json']['priority']);

        $client->liveActivities()->activity('order_42')->status('Delivered')->dismissAt('2026-01-01T12:00:00Z')->end();
        $e = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/order_42/end', $e['url']);
        $this->assertSame('Delivered', $e['json']['contentState']['status']);
        $this->assertSame('2026-01-01T12:00:00Z', $e['json']['dismissalDate']);
    }

    public function testLiveActivityBuilderStartRequiresType(): void
    {
        [$client] = $this->make();
        $this->expectException(\InvalidArgumentException::class);
        $client->liveActivities()->activity('order_42')->status('Preparing')->start(); // no type
    }

    public function testLiveActivityEmptyContentStateSerialisesAsObject(): void
    {
        [$client, $transport] = $this->make();
        $client->liveActivities()->update('a1', []);

        // Must be an object ({}), never [] — the API validates it.
        $this->assertInstanceOf(\stdClass::class, $transport->lastRequest()['json']['contentState']);
    }

    public function testLiveActivityTemplatedStart(): void
    {
        [$client, $transport] = $this->make(new ApiResponse(200, '{"ios":1,"android":2,"iosTargets":1}'));

        $result = $client->liveActivities()->template('order_tracking', 'order_42')
            ->step('pending')
            ->etaAt(new \DateTimeImmutable('2026-10-21T12:00:00Z'))
            ->value('driver', 'Ahmed')
            ->values(['liters' => 500])
            ->toUser('u_1')
            ->lang('ckb')
            ->alert('Order received', '500 L')
            ->start();

        $this->assertSame(1, $result['iosTargets']);
        $req = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/start', $req['url']);
        $this->assertSame('order_tracking', $req['json']['template']);
        $this->assertArrayNotHasKey('activityType', $req['json']); // the server targets the SDK widget's type
        $this->assertArrayNotHasKey('contentState', $req['json']);
        $this->assertSame(['step' => 'pending', 'etaAt' => 1792584000, 'values' => ['driver' => 'Ahmed', 'liters' => 500]], $req['json']['data']);
        $this->assertSame(['u_1'], $req['json']['distinctIds']);
        $this->assertSame('ckb', $req['json']['lang']);
        $this->assertSame(['title' => 'Order received', 'body' => '500 L'], $req['json']['alert']);
    }

    public function testLiveActivityTemplatedPartialUpdateAndEnd(): void
    {
        [$client, $transport] = $this->make();

        $client->liveActivities()->activity('order_42')->step('arrived')->progress(0.5)->update();
        $u = $transport->lastRequest();
        $this->assertSame('https://api.example.test/partner/live-activities/order_42/update', $u['url']);
        $this->assertSame(['data' => ['step' => 'arrived', 'progress' => 0.5]], $u['json']);

        $client->liveActivities()->activity('order_42')->step('completed')->end();
        $this->assertSame(['data' => ['step' => 'completed']], $transport->lastRequest()['json']);

        $client->liveActivities()->activity('order_42')->canceled()->end();
        $this->assertSame(['data' => ['state' => 'canceled']], $transport->lastRequest()['json']);
    }

    public function testLiveActivityProgressAloneStaysPlain(): void
    {
        [$client, $transport] = $this->make();
        $client->liveActivities()->activity('order_42')->status('On the way')->progress(70)->update();
        $this->assertSame(['contentState' => ['status' => 'On the way', 'progress' => 70]], $transport->lastRequest()['json']);
    }

    public function testLiveActivityTemplatePlainMethodsAndEmptyData(): void
    {
        [$client, $transport] = $this->make();

        $client->liveActivities()->startTemplate('order_tracking', 'o1');
        $data = $transport->lastRequest()['json']['data'];
        $this->assertInstanceOf(\stdClass::class, $data); // encodes as {} — never []
        $this->assertSame('{}', json_encode($data));

        $client->liveActivities()->startTemplate(['layout' => 'steps', 'steps' => [['id' => 'a'], ['id' => 'b']]], 'o2', ['step' => 'a']);
        $this->assertCount(2, $transport->lastRequest()['json']['template']['steps']);

        $client->liveActivities()->updateData('o2', ['step' => 'b', 'etaAt' => null]);
        $this->assertSame(['data' => ['step' => 'b', 'etaAt' => null]], $transport->lastRequest()['json']); // null clears it

        $client->liveActivities()->end('o2', ['data' => ['state' => 'done']]);
        $this->assertSame(['data' => ['state' => 'done']], $transport->lastRequest()['json']);
    }

    public function testLiveActivityEtaAcceptsMilliseconds(): void
    {
        [$client, $transport] = $this->make();
        $client->liveActivities()->template('t', 'o')->etaAt(1792584000123)->start();
        $this->assertSame(1792584000, $transport->lastRequest()['json']['data']['etaAt']);
    }

    public function testLiveActivityStartNeedsTemplateOrType(): void
    {
        [$client] = $this->make();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/template/');
        $client->liveActivities()->activity('o')->step('a')->start();
    }
}
