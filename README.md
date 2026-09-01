# Nexus PHP SDK

Official **server-side** PHP SDK for the [Inverge Nexus](https://nexus.inverge.net)
platform — realtime, product analytics, error monitoring, structured logs,
feature flags, deep-link attribution and the sessions spine.

Framework-agnostic core (zero required dependencies beyond `ext-curl`) with
first-class **Laravel** and **Symfony** integrations. Works in any PHP app —
native, Laravel, Symfony, Slim, WordPress, …

- PHP **8.1+**
- Talks to the `x-api-key`-authenticated `/partner/*` HTTP API
- Bring-your-own HTTP client (any PSR-18) or use the built-in cURL transport

## Install

```bash
composer require inverge/nexus
```

## Quick start (any PHP)

```php
use Inverge\Nexus\NexusClient;

$nexus = NexusClient::create('nxs_live_xxx'); // or pass ['base_url' => '...', 'timeout' => 10]

// Realtime: emit to a room
$nexus->realtime()->emit('orders:42', 'status', ['state' => 'shipped']);

// ...to several rooms with the same event, in one request
$nexus->realtime()->emitToRooms(['orders:1', 'orders:2'], 'location', ['lat' => 36.2, 'lng' => 43.9]);

// ...or fully custom per room/events/payload in one request
$nexus->realtime()->broadcast([
    ['room' => 'orders:1', 'event' => 'location', 'payload' => ['lat' => 1]],
    ['room' => 'orders:2', 'events' => ['location', 'eta'], 'payload' => ['lat' => 2]],
]);

// Analytics
$nexus->events()->capture('order_placed', ['total' => 42.0], ['distinctId' => 'user_1']);

// Errors
try {
    // ...
} catch (\Throwable $e) {
    $nexus->errors()->captureException($e, ['distinctId' => 'user_1']);
}

// Logs
$nexus->logs()->info('Payment captured', ['context' => ['order' => 42]]);

// Feature flags
if ($nexus->flags()->isEnabled('new_checkout', 'user_1')) {
    // ...
}
```

## Laravel

Auto-discovered — just set the env vars:

```dotenv
NEXUS_API_KEY=nxs_live_xxx
NEXUS_BASE_URL=https://api.nexus.inverge.net
```

Optionally publish the config: `php artisan vendor:publish --tag=nexus-config`.

Use the facade or inject the client:

```php
use Inverge\Nexus\Laravel\Nexus;

Nexus::realtime()->emit('orders:42', 'status', ['state' => 'shipped']);
Nexus::events()->capture('order_placed', ['total' => 42], ['distinctId' => auth()->id()]);
```

```php
use Inverge\Nexus\NexusClient;

class OrderController
{
    public function __construct(private NexusClient $nexus) {}

    public function ship(Order $order): void
    {
        $this->nexus->realtime()->emit("orders:{$order->customer_id}", 'status', ['state' => 'shipped']);
    }
}
```

### Notifications

Drive Nexus from your notification classes — add the channel to `via()` and
return a `NexusMessage` from `toNexus()`:

```php
use Illuminate\Notifications\Notification;
use Inverge\Nexus\Laravel\Notifications\NexusChannel;
use Inverge\Nexus\Laravel\Notifications\NexusMessage;

class OrderShipped extends Notification // implements ShouldQueue to deliver off-request
{
    public function __construct(private Order $order) {}

    public function via(object $notifiable): array
    {
        return [NexusChannel::class]; // or 'nexus'
    }

    public function toNexus(object $notifiable): NexusMessage
    {
        return NexusMessage::create()
            ->event('order_shipped', ['order' => $this->order->id])
            ->emit("orders:{$this->order->customer_id}", 'status', ['state' => 'shipped'])
            ->info("Order {$this->order->id} shipped");
        // also: ->captureError(...), ->captureException($e), ->warning(...), ->to($distinctId)
    }
}
```

One message can do several things at once (event + emit + log + error). The
notifiable's identity is applied automatically if it exposes it:

```php
public function routeNotificationForNexus(object $notification): string
{
    return (string) $this->id; // distinctId — or return an identity array
}
```

## Symfony

Register the bundle in `config/bundles.php`:

```php
Inverge\Nexus\Symfony\NexusBundle::class => ['all' => true],
```

Configure `config/packages/nexus.yaml`:

```yaml
nexus:
    api_key: '%env(NEXUS_API_KEY)%'
    base_url: 'https://api.nexus.inverge.net'
    timeout: 10.0
```

Then autowire `NexusClient`:

```php
use Inverge\Nexus\NexusClient;

final class OrderService
{
    public function __construct(private readonly NexusClient $nexus) {}

    public function ship(Order $order): void
    {
        $this->nexus->realtime()->emit("orders:{$order->customerId}", 'status', ['state' => 'shipped']);
    }
}
```

## Log forwarding, auto error capture & queued delivery

### Laravel

Flip on log forwarding and automatic error capture with env vars:

```dotenv
NEXUS_LOGGING=true          # ship logs to Nexus Logs (batched)
NEXUS_CAPTURE_ERRORS=true   # unhandled exceptions -> Nexus Errors (default true)
NEXUS_QUEUE=true            # deliver via the queue instead of inline (optional)
NEXUS_QUEUE_CONNECTION=redis
NEXUS_QUEUE_NAME=default
```

With `NEXUS_LOGGING=true` the SDK attaches a Monolog handler to your default log
channel: every `Log::info(...)` flows to Nexus (buffered into one batched request
per request lifecycle), and any logged exception is reported to Nexus Errors with
a full stacktrace. With `NEXUS_QUEUE=true` that delivery moves onto the queue so
it never touches request latency.

For fire-and-forget telemetry from your own code, resolve the queued client:

```php
app('nexus.queue')->events()->capture('order_placed', ['total' => 42], ['distinctId' => $userId]);
```

### Symfony

Auto error capture is on by default — the bundle registers a `kernel.exception`
subscriber. Toggle it in `config/packages/nexus.yaml`:

```yaml
nexus:
    api_key: '%env(NEXUS_API_KEY)%'
    capture_errors: true
```

To forward logs, add the provided Monolog handler service in `config/packages/monolog.yaml`:

```yaml
monolog:
    handlers:
        nexus:
            type: service
            id: Inverge\Nexus\Monolog\NexusLogHandler
```

**Async delivery** (parity with Laravel's queue) — requires `symfony/messenger`:

```yaml
# config/packages/nexus.yaml
nexus:
    api_key: '%env(NEXUS_API_KEY)%'
    async: true            # dispatch telemetry (errors/logs) via Messenger
```

```yaml
# config/packages/messenger.yaml — route the message to an async transport
framework:
    messenger:
        routing:
            'Inverge\Nexus\Symfony\Messenger\NexusDeliveryMessage': async
```

With `async: true`, error capture and the log handler dispatch a
`NexusDeliveryMessage` onto the bus; a bundled handler delivers it in the worker.

### Verify your setup (Laravel)

```bash
php artisan nexus:test              # checks config + auth (lists rooms)
php artisan nexus:test --room=demo  # also emits a test event to the "demo" room
```

## Bring your own HTTP client (PSR-18)

The default transport uses cURL. To use Guzzle (or any PSR-18 client), inject a
`Psr18Transport`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Inverge\Nexus\{Config, NexusClient};
use Inverge\Nexus\Http\Psr18Transport;

$factory = new HttpFactory();
$transport = new Psr18Transport(new Client(['timeout' => 10]), $factory, $factory);

$nexus = new NexusClient(new Config('nxs_live_xxx'), $transport);
```

## API surface

| Resource | Methods |
|---|---|
| `realtime()` | `emit`, `emitToRooms`, `broadcast`, `registerRoom`, `rooms`, `deleteRoom`, `link`, `unlink`, `schema`, `setSchema`, `enableSchema`, `clearSchema`, `related` |
| `events()` | `capture`, `batch` |
| `errors()` | `capture`, `captureException` |
| `logs()` | `log`, `trace`, `debug`, `info`, `warn`, `error`, `fatal`, `batch` |
| `sessions()` | `identify`, `track` |
| `flags()` | `evaluate`, `isEnabled`, `variant`, `payload` |
| `links()` | `attribute` |

Every call throws `Inverge\Nexus\Exception\ApiException` on a non-2xx response
(with `->status`, `->errorCode`, `->details`) and `TransportException` on a
network failure — both extend `NexusException`.

## Development

```bash
composer install
composer test   # vendor/bin/phpunit
```

MIT licensed.
