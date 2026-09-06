<?php

declare(strict_types=1);

namespace Inverge\Nexus\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * `Nexus` facade for Laravel.
 *
 * ```php
 * Nexus::realtime()->emit('orders:42', 'status', ['state' => 'shipped']);
 * Nexus::events()->capture('order_placed', ['total' => 42], ['distinctId' => 'u_1']);
 * ```
 *
 * @method static \Inverge\Nexus\Resource\Realtime realtime()
 * @method static \Inverge\Nexus\Resource\Events    events()
 * @method static \Inverge\Nexus\Resource\Errors    errors()
 * @method static \Inverge\Nexus\Resource\Logs      logs()
 * @method static \Inverge\Nexus\Resource\Sessions  sessions()
 * @method static \Inverge\Nexus\Resource\Flags     flags()
 * @method static \Inverge\Nexus\Resource\Links     links()
 * @method static \Inverge\Nexus\Resource\Surveys   surveys()
 * @method static \Inverge\Nexus\Resource\RemoteConfig remoteConfig()
 * @method static \Inverge\Nexus\Resource\Push       push()
 * @method static \Inverge\Nexus\Resource\InApp      inApp()
 * @method static \Inverge\Nexus\Resource\LiveActivities liveActivities()
 *
 * @see \Inverge\Nexus\NexusClient
 */
final class Nexus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nexus';
    }
}
