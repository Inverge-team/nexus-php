# Nexus PHP SDK — Full Reference

`inverge/nexus` is the server‑side client for the Inverge Nexus platform:
**realtime messaging, product analytics, structured logging, error monitoring,
sessions, feature flags, remote config, deep‑link attribution, and surveys** —
from plain PHP, Laravel, or Symfony.

- Package: `inverge/nexus`
- PHP: `>= 8.1`

---

## Table of contents

1. [Installation](#1-installation)
2. [Plain PHP setup](#2-plain-php-setup)
3. [Laravel setup](#3-laravel-setup)
4. [Symfony setup](#4-symfony-setup)
5. [Dispatchers (sync vs queued)](#5-dispatchers-sync-vs-queued)
6. [Sessions](#6-sessions)
7. [Events](#7-events)
8. [Logs](#8-logs)
9. [Errors](#9-errors)
10. [Feature flags](#10-feature-flags)
11. [Remote Config](#11-remote-config)
12. [Deep links & attribution](#12-deep-links--attribution)
13. [Realtime](#13-realtime)
14. [Surveys](#14-surveys)
15. [Push notifications](#15-push-notifications)
16. [In‑app messages](#16-in-app-messages)
17. [Live Activities](#17-live-activities)
18. [Monolog handler](#18-monolog-handler)
19. [Low‑level request](#19-low-level-request)

---

## 1. Installation

```bash
composer require inverge/nexus
```

---

## 2. Plain PHP setup

```php
use Inverge\Nexus\NexusClient;
use Inverge\Nexus\Config;

$nexus = new NexusClient(new Config(
    apiKey: 'nxs_live_xxx',
    baseUrl: 'https://services.inverge.net',  // optional
    timeout: 10.0,                          // optional (seconds)
    defaultHeaders: [],                     // optional
));

$nexus->events()->capture('order_placed', ['total' => 42.0], ['distinctId' => 'user_1']);
```

`Config` fields: `apiKey` (required), `baseUrl`, `timeout`, `defaultHeaders`.
`Config::fromArray('nxs_…', [...])` is also available.

Resources: `realtime()`, `events()`, `errors()`, `logs()`, `sessions()`,
`flags()`, `links()`, `surveys()`, `remoteConfig()`, `push()`, `inApp()`,
`liveActivities()`. Plus `config()` and `request()`.

---

## 3. Laravel setup

The service provider auto‑registers. Publish config if you want to tweak it:

```bash
php artisan vendor:publish --tag=nexus-config
```

`.env`:

```dotenv
NEXUS_API_KEY=nxs_live_xxx
NEXUS_BASE_URL=https://services.inverge.net
NEXUS_TIMEOUT=10
NEXUS_QUEUE=false            # true → dispatch telemetry on the queue
NEXUS_QUEUE_CONNECTION=
NEXUS_QUEUE_NAME=
NEXUS_LOGGING=false          # true → forward Laravel logs to Nexus
NEXUS_LOG_LEVEL=debug
NEXUS_CAPTURE_ERRORS=true    # report unhandled exceptions automatically
```

Use the facade anywhere:

```php
use Inverge\Nexus\Laravel\Nexus;

Nexus::realtime()->emit('orders:42', 'status', ['state' => 'shipped']);
Nexus::events()->capture('order_placed', ['total' => 42], ['distinctId' => auth()->id()]);
Nexus::remoteConfig()->all(['userProperties' => ['governorate' => 'Erbil']]);
```

Queued variant (offloads the HTTP call to a job):

```php
app('nexus.queue')->events()->capture('order_placed', ['total' => 42], ['distinctId' => $userId]);
```

Unhandled exceptions are reported automatically when `NEXUS_CAPTURE_ERRORS=true`.

---

## 4. Symfony setup

Register the bundle and configure it:

```yaml
# config/packages/nexus.yaml
nexus:
    api_key: '%env(NEXUS_API_KEY)%'
    base_url: 'https://services.inverge.net'
```

Inject `NexusClient` via autowiring; an exception subscriber reports uncaught
exceptions, and a Messenger handler is available for async dispatch.

---

## 5. Dispatchers (sync vs queued)

The client sends requests through a **dispatcher**. Default is synchronous cURL.
Swap it for a queued one (Laravel `QueueDispatcher`, Symfony
`MessengerDispatcher`) so telemetry never blocks the request:

```php
$queued = $nexus->withDispatcher($myDispatcher); // returns a new client
```

You can also inject a custom PSR‑18 transport: `new NexusClient($config, $psr18Transport)`.

---

## 6. Sessions

```php
// Identify an end‑user:
$nexus->sessions()->identify('user_123', [
    'email'  => 'a@b.com',
    'name'   => 'Ada',
    'traits' => ['plan' => 'pro'],
]);

// Start/refresh a session:
$nexus->sessions()->track([
    'distinctId' => 'user_123',
    'deviceKey'  => 'dev_abc',
    'sessionKey' => 'sess_abc',
    'country'    => 'IQ',
]);
```

Returns the server payload (incl. `sessionId`).

---

## 7. Events

```php
// One event → returns the number written (0/1):
$nexus->events()->capture('order_placed', ['total' => 42.0, 'currency' => 'USD'], [
    'distinctId' => 'user_1',
    'sessionKey' => 'sess_1',
]);

// A batch of events sharing one identity/context:
$nexus->events()->batch([
    ['name' => 'view',  'properties' => ['sku' => 'A1']],
    ['name' => 'click', 'properties' => ['sku' => 'A1']],
], ['distinctId' => 'user_1']);
```

`capture(string $name, array $properties = [], array $context = []): int`.
Context keys: `distinctId, sessionKey, deviceKey, release, osType, osVersion,
browser, appVersion, timestamp`.

---

## 8. Logs

```php
$nexus->logs()->info('payment started', ['source' => 'checkout']);
$nexus->logs()->error('charge failed', ['context' => ['code' => 'declined']]);

// Generic + all levels: trace, debug, info, warn, error, fatal
$nexus->logs()->log('warn', 'retrying', ['context' => ['attempt' => 2]]);

// Batch:
$nexus->logs()->batch([
    ['level' => 'info',  'message' => 'a'],
    ['level' => 'error', 'message' => 'b'],
], ['distinctId' => 'user_1']);
```

Each level method: `info(string $message, array $options = []): int`. Options
include `source`, `context`, and the usual identity keys.

---

## 9. Errors

```php
// From a caught throwable (recommended):
try {
    doWork();
} catch (\Throwable $e) {
    $nexus->errors()->captureException($e, [
        'handled' => true,
        'level'   => 'error',
        'context' => ['feature' => 'checkout'],
        'distinctId' => 'user_1',
    ]);
}

// Or a manual error:
$nexus->errors()->capture('Payment gateway timeout', [
    'type' => 'GatewayTimeout',
    'level' => 'error',
    'fingerprint' => 'gateway-timeout',
]);
```

Options: `type, level, handled, fingerprint, stack, context, release, url,
distinctId, sessionKey, deviceKey, osType, osVersion, browser, appVersion`.

---

## 10. Feature flags

```php
$nexus->flags()->isEnabled('new_checkout', 'user_1', ['plan' => 'pro']); // bool
$nexus->flags()->variant('paywall', 'user_1');                           // ?string
$nexus->flags()->payload('paywall', 'user_1');                           // mixed
$all = $nexus->flags()->evaluate('user_1', ['plan' => 'pro']);           // full result
```

`evaluate(string $distinctId, array $properties = []): array` returns every
flag; the others are convenience wrappers.

---

## 11. Remote Config

Fetch the active, published template resolved for a context (conditions —
platform, version, country, percentile, **custom attributes** — evaluated
server‑side).

```php
// Flat key => value map:
$config = $nexus->remoteConfig()->all([
    'appVersion'     => '2.1.0',
    'country'        => 'IQ',
    'userProperties' => ['governorate' => 'Duhok'],
]);
$phone = $config['phone_number'];

// A single value with a default:
$phone = $nexus->remoteConfig()->get('phone_number', '+9640000000000', [
    'userProperties' => ['governorate' => 'Erbil'],
]);

// Full result (version, etag, per‑parameter value + which condition supplied it):
$result = $nexus->remoteConfig()->fetch(
    ['userProperties' => ['governorate' => 'Duhok']],
    $previousEtag, // optional If-None-Match; result['notModified'] === true when unchanged
);
// $result['parameters']['phone_number'] === ['value' => ..., 'valueType' => 'STRING', 'source' => 'Duhok']
```

Context keys: `appInstanceId, appVersion, appBuild, platform, osVersion,
country, language, firstOpenTime, userProperties`. `userProperties` values must
be primitives (strings/numbers/bools) and match condition values exactly.

- `fetch(array $context = [], ?string $etag = null): array`
- `all(array $context = []): array`
- `get(string $key, mixed $default = null, array $context = []): mixed`

---

## 12. Deep links & attribution

```php
$data = $nexus->links()->attribute('install', [
    'clickId'    => 'abc',
    'name'       => 'summer_sale',
    'platform'   => 'ios',
    'distinctId' => 'user_1',
    'properties' => ['campaign' => 'promo'],
]);
```

`attribute(string $type, array $options = []): array`. Types: `install`, `open`,
`reengagement`, … Options include `name, clickId, distinctId, deviceId,
sessionKey, platform, osType, country, revenue, properties`.

---

## 13. Realtime

Server‑to‑client fan‑out and room management over the data plane.

```php
use Inverge\Nexus\RoomMessage;

// Emit one or more events to a room:
$nexus->realtime()->emit('orders:42', 'status', ['state' => 'shipped']);
$nexus->realtime()->emit('orders:42', ['status', 'updated'], ['state' => 'shipped']);

// Emit with a RoomMessage value object:
$nexus->realtime()->emit(new RoomMessage('orders:42', ['status'], ['state' => 'shipped']));

// Batch many messages:
$nexus->realtime()->broadcast([
    new RoomMessage('room:a', ['ping'], ['n' => 1]),
    new RoomMessage('room:b', ['ping'], ['n' => 2]),
]);

// Same payload to several rooms:
$nexus->realtime()->emitToRooms(['a', 'b'], 'ping', ['n' => 1]);

// Room management:
$nexus->realtime()->registerRoom('orders', 'standard');
$rooms = $nexus->realtime()->rooms();
$nexus->realtime()->deleteRoom($roomId);
$nexus->realtime()->related('orders');

// Link / unlink related rooms:
$nexus->realtime()->link($roomId, $relatedId);
$nexus->realtime()->unlink($roomId, $relatedId);

// Payload schema (validation):
$nexus->realtime()->setSchema($roomId, ['type' => 'object', 'required' => ['state']]);
$nexus->realtime()->enableSchema($roomId, true);
$nexus->realtime()->schema($roomId);
$nexus->realtime()->clearSchema($roomId);
```

---

## 14. Surveys

```php
// Surveys a user is eligible for (targeting/sampling/capping applied):
$surveys = $nexus->surveys()->active([
    'distinctId' => 'user_1',
    'properties' => ['plan' => 'pro'],
    'osType'     => 'ios',
]);

// Submit a response (answers keyed by question id):
$nexus->surveys()->respond('survey_1', ['q1' => 9, 'q2' => 'Great'], [
    'completed'  => false,
    'distinctId' => 'user_1',
]);

// Convenience wrappers:
$nexus->surveys()->complete('survey_1', ['q1' => 9], ['distinctId' => 'user_1']);
$nexus->surveys()->dismiss('survey_1', ['distinctId' => 'user_1']);
```

---

## 15. Push notifications

Send transactional push to **users or segments** across their registered devices.
Campaigns, provider credentials, segments and templates live in the console; the
server SDK is for one‑off, event‑driven sends.

**Fluent builder** (recommended):

```php
$nexus->push()->notification()
    ->title('Your order shipped')
    ->body('Track it in the app')
    ->image('https://cdn.example.com/box.png')
    ->data(['screen' => '/orders/42'])
    ->button('track', 'Track', ['url' => 'https://x/track'])
    ->iosBadge(1)
    ->androidVisibility('public')
    ->setSegments(['vip', 'active_7d'])   // union of saved segments
    ->send();                             // → ['sent' => .., 'failed' => .., 'recipients' => ..]
```

Targeting (combine freely; union):

```php
->toUsers(['user_1', 'user_2'])          // or ->toUser('user_1')
->setSegments(['vip'])                    // aliases: ->toSegments([...]) / ->toSegment('vip')
->where(['platform' => 'ios', 'lang' => 'en'])   // ad-hoc device filter (incl. tags)
->toAll()                                 // everyone (overrides other targets)
```

Options — action buttons plus per-platform extras: `iosBadge`,
`iosRelevanceScore`, `iosInterruptionLevel`, `iosSubtitle`, `androidVisibility`,
`androidLargeIcon`, `androidBigPicture`, `androidAccentColor`, and `option()` /
`options()` for anything else (web icon/image/badge, small icon, …).

**Quick send** (no builder):

```php
$nexus->push()->send(['user_1'], ['title' => 'Welcome 👋']);
$nexus->push()->sendToUser('user_1', ['title' => 'Welcome 👋']);
```

---

## 16. In‑app messages

In‑app messages are composed in the console and shown by the client SDK. Server‑
side you can fetch the ones a user is eligible for (headless / server‑driven UIs)
and record impressions / clicks.

```php
$messages = $nexus->inApp()->active(['distinctId' => 'user_1']);

$nexus->inApp()->impression('msg_1', ['distinctId' => 'user_1']);
$nexus->inApp()->click('msg_1', 'cta', ['distinctId' => 'user_1']);
$nexus->inApp()->dismiss('msg_1', ['distinctId' => 'user_1']);
```

---

## 17. Live Activities

A live, updating view of an in‑progress event on the iOS Lock Screen / Dynamic
Island and as an Android live notification — driven from your backend. Start when
the event begins, update as its status changes, end when it's done.

**Fluent builder** (recommended):

```php
// Start — pass the activity type + id; ->toUser(..) for per-order, ->shared() for many.
$nexus->liveActivities()->activity('DeliveryAttributes', 'order_42')
    ->title('Order #42')->status('Preparing')->progress(20)
    ->toUser('user_1')->priority(10)
    ->start();

// Update — activity type not needed; priority 5 = routine (unmetered), 10 = immediate.
$nexus->liveActivities()->activity('order_42')
    ->status('On the way')->progress(70)->priority(5)
    ->update();

// End — optional final state + dismissal time.
$nexus->liveActivities()->activity('order_42')
    ->status('Delivered')->dismissAt('2026-01-01T12:00:00Z')
    ->end();
```

Content-state helpers: `title/subtitle/body/status/progress`, plus `set($k,$v)` /
`state([...])` for custom fields. Or call the plain methods
(`start()/update()/end()`) with arrays if you prefer.

> **iOS** requires the APNs key configured in the console (Live Activities can't
> go through FCM) and a Widget Extension in your app. **Android** renders a live
> ongoing notification with no extra setup.

---

## 18. Monolog handler

Forward your app's Monolog records to Nexus logs:

```php
use Inverge\Nexus\Monolog\NexusLogHandler;

$logger->pushHandler(new NexusLogHandler($nexus /*, level, bubble, flushAt */));
```

In Laravel, set `NEXUS_LOGGING=true` (and `NEXUS_LOG_LEVEL`) to wire this
automatically.

---

## 19. Low‑level request

For endpoints without a dedicated resource method:

```php
$response = $nexus->request('POST', '/partner/events', ['events' => [...]], [
    'If-None-Match' => $etag, // extra headers
]);
```

`request(string $method, string $path, ?array $json = null, array $headers = []): ?array`
— returns the decoded body, or `null` on failure.
