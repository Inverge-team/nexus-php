<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/** @implements \IteratorAggregate<int, DocumentSnapshot> */
final class QueryResult implements \IteratorAggregate, \Countable
{
    /** @param list<DocumentSnapshot> $docs */
    public function __construct(
        public readonly array $docs,
        /** Pass to `Query::startAfter()` for the next page. */
        public readonly ?string $nextCursor,
        public readonly bool $hasMore,
        /** Set when the query used `withCount()`. */
        public readonly ?int $total = null,
    ) {
    }

    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->docs); }
    public function count(): int { return count($this->docs); }
}
