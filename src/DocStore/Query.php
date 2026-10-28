<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/** An immutable query — every builder returns a new one. */
class Query
{
    /** @param array<string, mixed> $state */
    public function __construct(
        protected readonly DocStore $db,
        public readonly string $collection,
        protected readonly array $state = [],
    ) {
    }

    protected function with(array $patch): static
    {
        return new static($this->db, $this->collection, array_merge($this->state, $patch));
    }

    /** `where('total', '>=', 100)`, or a composed filter (`Filter::or(...)`, raw JSON array). */
    public function where(string|array $fieldOrFilter, ?string $op = null, mixed $value = null): static
    {
        $extra = is_string($fieldOrFilter) ? Filter::where($fieldOrFilter, $op ?? '==', $value) : $fieldOrFilter;
        $cur = $this->state['filter'] ?? null;
        return $this->with(['filter' => $cur === null ? $extra : ['$and' => [$cur, $extra]]]);
    }

    public function orderBy(string $field, string $dir = 'asc'): static
    {
        $sort = $this->state['sort'] ?? [];
        $sort[] = ['field' => $field === 'id' ? '$id' : $field, 'dir' => strtolower($dir) === 'desc' ? 'desc' : 'asc'];
        return $this->with(['sort' => $sort]);
    }

    public function limit(int $n): static { return $this->with(['limit' => $n]); }
    /** Keyset pagination: the `nextCursor` of the previous page. */
    public function startAfter(string $cursor): static { return $this->with(['cursor' => $cursor]); }
    public function offset(int $n): static { return $this->with(['offset' => $n]); }
    public function select(string ...$fields): static { return $this->with(['select' => array_values($fields)]); }
    public function expand(string ...$relations): static { return $this->with(['expand' => array_values([...($this->state['expand'] ?? []), ...$relations])]); }
    /** Full-text search over the collection's search fields. */
    public function search(string $text): static { return $this->with(['search' => $text]); }
    /** Time travel: the collection as it was at `$when`. */
    public function asOf(\DateTimeInterface|string $when): static { return $this->with(['asOf' => DocStore::iso($when)]); }
    public function withCount(): static { return $this->with(['count' => true]); }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter($this->state, fn ($v) => $v !== null);
    }

    public function get(): QueryResult
    {
        // A top-level body must be a non-empty array to encode as a JSON object.
        $body = $this->toArray() ?: ['filter' => new \stdClass()];
        $r = $this->db->send('POST', '/collections/' . rawurlencode($this->collection) . '/query', $body) ?? [];
        $docs = [];
        foreach ((array) ($r['items'] ?? []) as $d) {
            $docs[] = new DocumentSnapshot($this->collection, (string) $d['id'], $d);
        }
        return new QueryResult($docs, $r['nextCursor'] ?? null, (bool) ($r['hasMore'] ?? false), isset($r['total']) ? (int) $r['total'] : null);
    }

    /**
     * Every match, page by page (keyset — safe while documents change).
     *
     * @return \Generator<int, DocumentSnapshot>
     */
    public function stream(int $pageSize = 200): \Generator
    {
        $q = $this->limit($pageSize);
        while (true) {
            $page = $q->get();
            yield from $page->docs;
            if ($page->nextCursor === null) {
                return;
            }
            $q = $q->startAfter($page->nextCursor);
        }
    }

    public function count(): int
    {
        $groups = $this->aggregate(['n' => Filter::count()]);
        return (int) ($groups[0]['values']['n'] ?? 0);
    }

    /**
     * `aggregate(['n' => Filter::count(), 'revenue' => Filter::sum('total')], groupBy: ['status'])`
     * → `[['key' => ['status' => 'paid'], 'values' => ['n' => 12, 'revenue' => 940.5]], …]`.
     *
     * @param array<string, array<string, string>> $aggregations
     * @param list<string>|null                    $groupBy
     * @return list<array{key: array<string, mixed>, values: array<string, int|float|null>}>
     */
    public function aggregate(array $aggregations, ?array $groupBy = null, ?int $limit = null): array
    {
        $body = array_filter([
            'filter' => $this->state['filter'] ?? null,
            'search' => $this->state['search'] ?? null,
            'asOf' => $this->state['asOf'] ?? null,
            'aggregations' => $aggregations,
            'groupBy' => $groupBy,
            'limit' => $limit,
        ], fn ($v) => $v !== null);
        $r = $this->db->send('POST', '/collections/' . rawurlencode($this->collection) . '/aggregate', $body) ?? [];
        return array_values((array) ($r['groups'] ?? []));
    }
}
