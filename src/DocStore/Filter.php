<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/**
 * Composable query conditions — the same JSON filter the API takes.
 *
 *     $q->where(Filter::or(Filter::where('total', '>=', 100), Filter::where('vip', '==', true)));
 */
final class Filter
{
    private const OPS = [
        '==' => '$eq', '!=' => '$ne', '<' => '$lt', '<=' => '$lte', '>' => '$gt', '>=' => '$gte',
        'in' => '$in', 'not-in' => '$nin', 'between' => '$between',
        'array-contains' => '$contains', 'array-contains-any' => '$containsAny', 'array-contains-all' => '$containsAll',
        'starts-with' => '$startsWith', 'ends-with' => '$endsWith', 'matches' => '$regex', 'exists' => '$exists',
        'size' => '$size', 'type' => '$type',
    ];

    /** @return array<string, mixed> */
    public static function where(string $field, string $op, mixed $value): array
    {
        $wire = self::OPS[$op] ?? throw new \InvalidArgumentException("Unknown DocStore operator '{$op}'");
        if ($value instanceof \DateTimeInterface) {
            $value = DocStore::iso($value);
        }
        return [$field === 'id' ? '$id' : $field => [$wire => $value]];
    }

    /** @param array<string, mixed> ...$filters */
    public static function or(array ...$filters): array { return ['$or' => array_values($filters)]; }
    /** @param array<string, mixed> ...$filters */
    public static function and(array ...$filters): array { return ['$and' => array_values($filters)]; }
    public static function not(array $filter): array { return ['$not' => $filter]; }
    /** Match through a relation: `Filter::related('author', Filter::where('verified', '==', true))`. */
    public static function related(string $relation, array $filter): array { return [$relation => ['$rel' => $filter]]; }

    public static function count(): array { return ['op' => 'count']; }
    public static function countDistinct(string $field): array { return ['op' => 'countDistinct', 'field' => $field]; }
    public static function sum(string $field): array { return ['op' => 'sum', 'field' => $field]; }
    public static function avg(string $field): array { return ['op' => 'avg', 'field' => $field]; }
    public static function min(string $field): array { return ['op' => 'min', 'field' => $field]; }
    public static function max(string $field): array { return ['op' => 'max', 'field' => $field]; }
}
