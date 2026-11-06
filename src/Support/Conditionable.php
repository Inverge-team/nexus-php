<?php

declare(strict_types=1);

namespace Inverge\Nexus\Support;

/**
 * Laravel-style `when()` / `unless()` for fluent builders, so a conditional step
 * doesn't break the chain:
 *
 * ```php
 * $push->when($image, fn ($p, $url) => $p->image($url))
 *      ->when($ids === [], fn ($p) => $p->toSegment('All Clients'), fn ($p) => $p->toUsers($ids));
 * ```
 *
 * Truthiness is PHP's own (`null`, `false`, `0`, `''`, `'0'` and `[]` are falsy).
 * A Closure `$value` is called with the builder first. Unlike Laravel's, these
 * always return the builder — whatever the callbacks return — so the chain's
 * type never changes.
 */
trait Conditionable
{
    /**
     * Run `$callback($this, $value)` when `$value` is truthy, else `$default($this, $value)`.
     */
    public function when(mixed $value, callable $callback, ?callable $default = null): static
    {
        $value = $value instanceof \Closure ? $value($this) : $value;
        if ($value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }

    /**
     * The inverse of {@see when()}: run `$callback` when `$value` is falsy.
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): static
    {
        $value = $value instanceof \Closure ? $value($this) : $value;
        if (!$value) {
            $callback($this, $value);
        } elseif ($default !== null) {
            $default($this, $value);
        }

        return $this;
    }
}
