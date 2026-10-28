<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/** Up to 500 writes committed atomically: all apply, or none do. */
class WriteBatch
{
    /** @var list<array<string, mixed>> */
    protected array $ops = [];

    public function __construct(protected readonly DocStore $db)
    {
    }

    /** @param array<string, mixed> $data */
    public function create(DocumentRef $ref, array $data): static
    {
        $this->ops[] = ['type' => 'create', 'collection' => $ref->collection, 'id' => $ref->id, 'data' => DocStore::obj($data)];
        return $this;
    }

    /** @param array<string, mixed> $data */
    public function set(DocumentRef $ref, array $data, bool $merge = false, ?int $ifVersion = null): static
    {
        $this->ops[] = array_filter(['type' => 'set', 'collection' => $ref->collection, 'id' => $ref->id, 'data' => DocStore::obj($data), 'merge' => $merge ?: null, 'precondition' => $ifVersion !== null ? ['version' => $ifVersion] : null], fn ($v) => $v !== null);
        return $this;
    }

    /** @param array<string, mixed> $data */
    public function update(DocumentRef $ref, array $data, ?int $ifVersion = null): static
    {
        $this->ops[] = array_filter(['type' => 'update', 'collection' => $ref->collection, 'id' => $ref->id, 'update' => FieldValue::toSpec($data), 'precondition' => $ifVersion !== null ? ['version' => $ifVersion] : null], fn ($v) => $v !== null);
        return $this;
    }

    public function delete(DocumentRef $ref, ?int $ifVersion = null): static
    {
        $this->ops[] = array_filter(['type' => 'delete', 'collection' => $ref->collection, 'id' => $ref->id, 'precondition' => $ifVersion !== null ? ['version' => $ifVersion] : null], fn ($v) => $v !== null);
        return $this;
    }

    /** @return list<array<string, mixed>> */
    protected function finalOps(): array
    {
        return $this->ops;
    }

    /** @return list<array<string, mixed>> per-operation results */
    public function commit(): array
    {
        if ($this->ops === []) {
            return [];
        }
        $r = $this->db->send('POST', '/commit', ['operations' => $this->finalOps()]) ?? [];
        return array_values((array) ($r['results'] ?? []));
    }
}
