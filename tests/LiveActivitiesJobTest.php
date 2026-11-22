<?php

declare(strict_types=1);

namespace Inverge\Nexus\Tests;

use Inverge\Nexus\Config;
use Inverge\Nexus\Http\ApiResponse;
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/** start / update / end are accepted (202) and delivered asynchronously; job() reads the outcome. */
final class LiveActivitiesJobTest extends TestCase
{
    public function testStartReturnsTheAcceptedJob(): void
    {
        $transport = new FakeTransport(new ApiResponse(202, '{"accepted":true,"jobId":"j1","activityId":"order_42","status":"pending"}'));
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);

        $res = $client->liveActivities()->template('order_tracking', 'order_42')->step('pending')->toUser('u_1')->start();

        $this->assertTrue($res['accepted']);
        $this->assertSame('j1', $res['jobId']);
    }

    public function testJobReadsTheOutcome(): void
    {
        $transport = new FakeTransport(new ApiResponse(200, '{"jobId":"j1","status":"done","result":{"ios":1,"android":0}}'));
        $client = new NexusClient(new Config('nxs_test', 'https://api.example.test'), $transport);

        $job = $client->liveActivities()->job('j1');

        $this->assertSame('done', $job['status']);
        $req = $transport->lastRequest();
        $this->assertSame('GET', $req['method']);
        $this->assertSame('https://api.example.test/partner/live-activities/jobs/j1', $req['url']);
    }
}
