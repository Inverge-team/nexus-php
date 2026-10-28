# Nexus PHP SDK

`inverge/nexus` is the server-side client for the **Inverge Nexus** platform —
from plain PHP, **Laravel** (facade, queued delivery, notification channels) or
**Symfony** (bundle, Messenger, Doctrine).

- Package: `inverge/nexus`
- PHP: `>= 8.1`

## Documentation

Installation, Laravel and Symfony setup, configuration and the full API
reference live on the documentation site:

- **Guide** — <https://docs.inverge.net/sdks/php>
- **API reference** — <https://docs.inverge.net/sdks/php/reference>

## What it covers

- **[Sessions](https://docs.inverge.net/sdks/php/reference/sessions)** — journey sessions that tie server-side activity to a user.
- **[Events](https://docs.inverge.net/sdks/php/reference/events)** — product analytics sent from your backend.
- **[Logs](https://docs.inverge.net/sdks/php/reference/logs)** — structured, leveled logs, plus a Monolog handler.
- **[Errors](https://docs.inverge.net/sdks/php/reference/errors)** — exceptions with context, reported to Nexus Errors.
- **[Feature flags](https://docs.inverge.net/sdks/php/reference/flags)** — evaluate flags per user on the server.
- **[Remote Config](https://docs.inverge.net/sdks/php/reference/remote-config)** — read remotely managed values, with targeting.
- **[Realtime](https://docs.inverge.net/sdks/php/reference/realtime)** — emit messages to rooms your clients are listening on.
- **[Deep links & attribution](https://docs.inverge.net/sdks/php/reference/links)** — create and resolve Nexus links.
- **[Surveys](https://docs.inverge.net/sdks/php/reference/surveys)** — manage and read in-product surveys.
- **[Push notifications](https://docs.inverge.net/sdks/php/reference/push)** — send push to users, devices and tags (also as a Laravel notification channel).
- **[In-app messages](https://docs.inverge.net/sdks/php/reference/inapp)** — trigger in-app messages for users.
- **[Live Activities](https://docs.inverge.net/sdks/php/reference/live-activities)** — start, update and end Live Activities from your server.
- **[Shield](https://docs.inverge.net/products/shield/verify)** — verify a request really comes from your app on a real device.
- **[Telescope](https://docs.inverge.net/products/telescope/php)** — request-journey tracing for requests, jobs, commands and
  scheduled tasks, with N+1, duplicate and slow queries flagged.
- **[DocStore](https://docs.inverge.net/sdks/php/reference/docstore)** — the document database: queries, transactions, history, change stream and webhook verification.

## Links

- Console — <https://nexus.inverge.net>
- Site — <https://inverge.net>
- Source — <https://github.com/Inverge-team/nexus-php>

MIT licensed. © Inverge.
