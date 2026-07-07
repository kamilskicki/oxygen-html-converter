<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Explicit output from native DOM-to-Oxygen element mapping.
 */
final class NativeMappingResult
{
    /**
     * @param array<string, mixed>|null $rootElement
     * @param list<array<string, mixed>> $children
     */
    public function __construct(
        private readonly ?array $rootElement,
        private readonly array $children
    ) {
    }

    public function hasRootElement(): bool
    {
        return $this->rootElement !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rootElement(): ?array
    {
        return $this->rootElement;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function children(): array
    {
        return $this->children;
    }
}
