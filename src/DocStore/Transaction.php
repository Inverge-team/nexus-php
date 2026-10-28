<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/** Read-modify-write; use through {@see DocStore::runTransaction()}. */
final class Transaction extends WriteBatch
{
    /** @var array<string, int> */
    private array $seen = [];

    public function get(DocumentRef $ref): DocumentSnapshot
    {
        $snap = $ref->get();
        $this->seen[$ref->path()] = $snap->version();
        return $snap;
    }

    protected function finalOps(): array
    {
        $out = [];
        foreach ($this->ops as $op) {
            $v = $this->seen["{$op['collection']}/{$op['id']}"] ?? null;
            if ($v !== null && !isset($op['precondition'])) {
                $op['precondition'] = ['version' => $v];
            }
            if ($op['type'] === 'create' && !isset($op['precondition'])) {
                $op['precondition'] = ['exists' => false];
            }
            $out[] = $op;
        }
        return $out;
    }
}
