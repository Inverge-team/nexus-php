<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/**
 * Server-side atomic field updates for {@see DocumentRef::update()} —
 * applied under a row lock, so concurrent increments never lose.
 *
 *     $ref->update(['views' => FieldValue::increment(1), 'tags' => FieldValue::arrayUnion('new')]);
 */
final class FieldValue
{
    private function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
        public readonly ?int $slice = null,
    ) {
    }

    public static function increment(int|float $n): self { return new self('inc', $n); }
    public static function multiply(int|float $n): self { return new self('mul', $n); }
    public static function minimum(mixed $v): self { return new self('min', $v); }
    public static function maximum(mixed $v): self { return new self('max', $v); }
    public static function arrayUnion(mixed ...$values): self { return new self('union', array_values($values)); }
    public static function arrayRemove(mixed ...$values): self { return new self('remove', array_values($values)); }
    /** Append (duplicates allowed); `$keepLast` caps the array — a rolling log. */
    public static function arrayPush(array $values, ?int $keepLast = null): self { return new self('push', array_values($values), $keepLast ? -$keepLast : null); }
    /** The server's clock at commit (ISO-8601). */
    public static function serverTimestamp(): self { return new self('serverTime'); }
    public static function delete(): self { return new self('delete'); }
    /** Deep-merge an object into the field (null values delete keys). */
    public static function merge(array $value): self { return new self('merge', $value); }

    /**
     * `['views' => increment(1), 'a.b' => 2]` → `['$inc' => [...], '$set' => [...]]`.
     * An array already written with `$` operators passes through.
     *
     * @param array<string, mixed> $update
     * @return array<string, mixed>
     */
    public static function toSpec(array $update): array
    {
        if ($update !== [] && array_reduce(array_keys($update), fn ($ok, $k) => $ok && str_starts_with((string) $k, '$'), true)) {
            return $update;
        }
        $spec = [];
        foreach ($update as $field => $v) {
            if (!$v instanceof self) {
                $spec['$set'][$field] = $v;
                continue;
            }
            match ($v->kind) {
                'inc' => $spec['$inc'][$field] = $v->value,
                'mul' => $spec['$mul'][$field] = $v->value,
                'min' => $spec['$min'][$field] = $v->value,
                'max' => $spec['$max'][$field] = $v->value,
                'union' => $spec['$addToSet'][$field] = ['$each' => $v->value],
                'remove' => $spec['$pull'][$field] = ['$in' => $v->value],
                'push' => $spec['$push'][$field] = $v->slice ? ['$each' => $v->value, '$slice' => $v->slice] : ['$each' => $v->value],
                'serverTime' => $spec['$serverTime'][] = $field,
                'delete' => $spec['$unset'][] = $field,
                'merge' => $spec['$merge'][$field] = $v->value,
            };
        }
        return $spec;
    }
}
