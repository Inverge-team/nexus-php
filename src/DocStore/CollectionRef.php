<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

final class CollectionRef extends Query
{
    public function doc(string $id): DocumentRef
    {
        return new DocumentRef($this->db, $this->collection, $id);
    }

    /**
     * Create with a generated, time-sortable id (or `$id`).
     *
     * @param array<string, mixed> $data
     */
    public function add(array $data, ?string $id = null): DocumentSnapshot
    {
        $r = $this->db->send('POST', '/collections/' . rawurlencode($this->collection) . '/docs', array_filter(['id' => $id, 'data' => DocStore::obj($data)], fn ($v) => $v !== null)) ?? [];
        return new DocumentSnapshot($this->collection, (string) ($r['id'] ?? $id), $r['doc'] ?? null);
    }

    /**
     * Point-in-time restore of the whole collection (service key) — written as
     * new versions, so it is undoable. `$dryRun` reports the plan only.
     *
     * @return array<string, mixed>
     */
    public function restore(\DateTimeInterface|string $asOf, bool $dryRun = false): array
    {
        return $this->db->send('POST', '/collections/' . rawurlencode($this->collection) . '/restore', ['asOf' => DocStore::iso($asOf), 'dryRun' => $dryRun]) ?? [];
    }
}
