<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

use Inverge\Nexus\Exception\ApiException;

final class DocumentRef
{
    public function __construct(
        private readonly DocStore $db,
        public readonly string $collection,
        public readonly string $id,
    ) {
    }

    public function path(): string { return "{$this->collection}/{$this->id}"; }

    private function url(): string
    {
        return '/collections/' . rawurlencode($this->collection) . '/docs/' . rawurlencode($this->id);
    }

    private function snap(?array $raw): DocumentSnapshot
    {
        return new DocumentSnapshot($this->collection, $this->id, $raw);
    }

    /**
     * Read; a missing document returns a snapshot whose `exists()` is false.
     *
     * @param list<string>|null $expand
     * @param list<string>|null $select
     */
    public function get(?array $expand = null, ?array $select = null, \DateTimeInterface|string|null $asOf = null): DocumentSnapshot
    {
        try {
            return $this->snap($this->db->send('GET', $this->url() . DocStore::qs([
                'expand' => $expand ? implode(',', $expand) : null,
                'select' => $select ? implode(',', $select) : null,
                'asOf' => $asOf !== null ? DocStore::iso($asOf) : null,
            ])));
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return $this->snap(null);
            }
            throw $e;
        }
    }

    /**
     * Create or replace (`$merge` deep-merges). `$ifVersion` refuses to
     * overwrite a change you haven't seen (0 = must not exist).
     *
     * @param array<string, mixed> $data
     */
    public function set(array $data, bool $merge = false, ?int $ifVersion = null): DocumentSnapshot
    {
        $r = $this->db->send('PUT', $this->url(), array_filter([
            'data' => DocStore::obj($data),
            'merge' => $merge ?: null,
            'precondition' => $ifVersion !== null ? ['version' => $ifVersion] : null,
        ], fn ($v) => $v !== null)) ?? [];
        return $this->snap($r['doc'] ?? null);
    }

    /**
     * Partial update with field values: `['views' => FieldValue::increment(1), 'a.b' => 2]`.
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data, ?int $ifVersion = null, bool $upsert = false): DocumentSnapshot
    {
        $r = $this->db->send('PATCH', $this->url(), array_filter([
            'update' => FieldValue::toSpec($data),
            'upsert' => $upsert ?: null,
            'precondition' => $ifVersion !== null ? ['version' => $ifVersion] : null,
        ], fn ($v) => $v !== null)) ?? [];
        return $this->snap($r['doc'] ?? null);
    }

    public function delete(?int $ifVersion = null): void
    {
        $this->db->send('DELETE', $this->url() . DocStore::qs(['ifVersion' => $ifVersion]));
    }

    /**
     * Every version, newest first, with the fields each one changed.
     *
     * @return list<array<string, mixed>>
     */
    public function history(?int $limit = null, ?int $beforeVersion = null): array
    {
        $r = $this->db->send('GET', $this->url() . '/history' . DocStore::qs(['limit' => $limit, 'beforeVersion' => $beforeVersion])) ?? [];
        return array_values((array) ($r['versions'] ?? []));
    }

    /** Back to an earlier version (written as a NEW version — undoable). */
    public function restore(int $version): DocumentSnapshot
    {
        $r = $this->db->send('POST', $this->url() . '/restore', ['version' => $version]) ?? [];
        return $this->snap($r['doc'] ?? null);
    }
}
