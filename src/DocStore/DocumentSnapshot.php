<?php

declare(strict_types=1);

namespace Inverge\Nexus\DocStore;

/** One document as read (or returned by a write). `exists()` is false for a missing one. */
final class DocumentSnapshot
{
    /** @param array<string, mixed>|null $raw */
    public function __construct(
        public readonly string $collection,
        public readonly string $id,
        private readonly ?array $raw,
    ) {
    }

    public function exists(): bool { return $this->raw !== null; }

    /** @return array<string, mixed>|null */
    public function data(): ?array { return $this->raw === null ? null : (array) ($this->raw['data'] ?? []); }

    /** Read one field by dot path. */
    public function get(string $path, mixed $default = null): mixed
    {
        $cur = $this->data();
        foreach (explode('.', $path) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public function version(): int { return (int) ($this->raw['version'] ?? 0); }
    public function createdAt(): ?\DateTimeImmutable { return isset($this->raw['createdAt']) ? new \DateTimeImmutable((string) $this->raw['createdAt']) : null; }
    public function updatedAt(): ?\DateTimeImmutable { return isset($this->raw['updatedAt']) ? new \DateTimeImmutable((string) $this->raw['updatedAt']) : null; }
    /** @return array<string, mixed> Related documents requested with `expand`. */
    public function expand(): array { return (array) ($this->raw['expand'] ?? []); }
}
