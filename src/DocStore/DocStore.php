<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

use Inverge\Nexus\Contracts\Dispatcher;
use Inverge\Nexus\Exception\ApiException;
use Inverge\Nexus\Exception\NexusException;

/**
 * Nexus DocStore — the document database.
 *
 *     $db = $nexus->docstore();                      // service key: NEXUS_DOCSTORE_SERVICE_KEY
 *     $db->doc('orders/o1')->update(['total' => FieldValue::increment(5)]);
 *     $open = $db->collection('orders')->where('status', '==', 'open')->orderBy('total', 'desc')->limit(20)->get();
 *     $asAna = $db->asUser($idToken);                // same API, security rules apply
 *
 * Unlike telemetry, every DocStore call THROWS on failure ({@see ApiException},
 * {@see ServiceDisabledException}) and always runs synchronously, even when
 * the client delivers telemetry through a queue.
 */
final class DocStore
{
    public const SERVICE_KEY_ENV = 'NEXUS_DOCSTORE_SERVICE_KEY';

    /** @param \Closure(): bool $enabled */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly \Closure $enabled,
        private readonly ?string $serviceKey = null,
        private readonly ?string $token = null,
    ) {
        if ($serviceKey !== null && !str_starts_with($serviceKey, 'nxsk_')) {
            throw new \InvalidArgumentException("A DocStore service key starts with 'nxsk_'");
        }
    }

    /** The same database as one end user (their JWT): security rules apply. */
    public function asUser(string $token): self
    {
        return new self($this->dispatcher, $this->enabled, $this->serviceKey, $token);
    }

    /** As an anonymous app user — rules see `auth == null`. */
    public function anonymous(): self
    {
        return new self($this->dispatcher, $this->enabled);
    }

    public function collection(string $name): CollectionRef
    {
        return new CollectionRef($this, $name);
    }

    /** `doc('posts/p1')`. */
    public function doc(string $path): DocumentRef
    {
        [$collection, $id] = array_pad(explode('/', $path, 2), 2, '');
        if ($collection === '' || $id === '') {
            throw new \InvalidArgumentException("'{$path}' is not a <collection>/<id> path");
        }
        return new DocumentRef($this, $collection, $id);
    }

    public function batch(): WriteBatch
    {
        return new WriteBatch($this);
    }

    /**
     * Run `$fn($tx)` optimistically: reads record versions, writes carry them,
     * and a concurrent change makes the commit fail and `$fn` run again.
     *
     * @template T
     * @param callable(Transaction): T $fn
     * @return T
     */
    public function runTransaction(callable $fn, int $maxAttempts = 5): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $tx = new Transaction($this);
            $result = $fn($tx);
            try {
                $tx->commit();
                return $result;
            } catch (ApiException $e) {
                if (!self::isPreconditionFailed($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep((int) (min(1.0, 0.025 * 2 ** $attempt) * (0.5 + mt_rand() / mt_getrandmax()) * 1_000_000));
            }
        }
    }

    /** A version precondition failed — someone else wrote first. */
    public static function isPreconditionFailed(ApiException $e): bool
    {
        return $e->status === 409 && ($e->details['reason'] ?? null) === 'precondition_failed';
    }

    /**
     * One page of the change stream (service key).
     *
     * @param list<string>|null $collections
     * @return array{changes: list<array<string, mixed>>, next: int, hasMore: bool}
     */
    public function changes(int $since = 0, ?array $collections = null, ?int $limit = null): array
    {
        $r = $this->send('GET', '/changes' . self::qs(['since' => $since, 'collections' => $collections ? implode(',', $collections) : null, 'limit' => $limit])) ?? [];
        return ['changes' => array_values((array) ($r['changes'] ?? [])), 'next' => (int) ($r['next'] ?? $since), 'hasMore' => (bool) ($r['hasMore'] ?? false)];
    }

    /**
     * Every committed change after `$since`, in commit order, until `$stop()`
     * returns true (forever by default). Persist each change's `seq` and pass
     * it back as `$since` to resume exactly where you left off.
     *
     * @param list<string>|null          $collections
     * @param (callable(): bool)|null    $stop
     * @return \Generator<int, array<string, mixed>>
     */
    public function watchChanges(int $since = 0, ?array $collections = null, float $pollInterval = 1.0, ?callable $stop = null): \Generator
    {
        $cursor = $since;
        while (!($stop !== null && $stop())) {
            $page = $this->changes($cursor, $collections, 500);
            foreach ($page['changes'] as $change) {
                yield $change;
            }
            $cursor = $page['next'];
            if (!$page['hasMore']) {
                usleep((int) ($pollInterval * 1_000_000));
            }
        }
    }

    /**
     * Collections as code (service key): create or update `$name` to match
     * `$config` (`rules`, `schema`, `relations`, `uniqueConstraints`, `searchFields`, …).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function deployCollection(string $name, array $config): array
    {
        if ($config === []) {
            // Refused rather than guessed: any "neutral" field we could send
            // instead (empty rules, a null description) would overwrite settings.
            throw new \InvalidArgumentException('deployCollection needs at least one setting (rules, schema, description, …)');
        }
        return $this->send('PUT', '/collections/' . rawurlencode($name), $config) ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function listCollections(): array
    {
        return array_values($this->send('GET', '/collections') ?? []);
    }

    /**
     * @internal
     * @param array<string, mixed>|null $json
     * @return array<mixed>|null
     */
    public function send(string $method, string $path, ?array $json = null): ?array
    {
        if (!($this->enabled)()) {
            throw new ServiceDisabledException('DocStore is switched off (NEXUS_DOCSTORE_ENABLED / services)');
        }
        $headers = [];
        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        } elseif ($this->serviceKey !== null) {
            $headers['x-nexus-service-key'] = $this->serviceKey;
        }
        return $this->dispatcher->dispatch($method, '/partner/docstore' . $path, $json, $headers);
    }

    /** @internal @param array<string, mixed> $params */
    public static function qs(array $params): string
    {
        $clean = array_filter($params, fn ($v) => $v !== null);
        return $clean === [] ? '' : '?' . http_build_query($clean);
    }

    /** @internal A NESTED empty PHP array must reach the API as `{}`, not `[]` (top-level bodies are arrays by contract). */
    public static function obj(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }

    public static function iso(\DateTimeInterface|string $when): string
    {
        if (is_string($when)) {
            return $when;
        }
        return \DateTimeImmutable::createFromInterface($when)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
