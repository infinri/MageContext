<?php

declare(strict_types=1);

namespace MageContext\Extractor;

class ExtractorRegistry
{
    /** @var ExtractorInterface[] */
    private array $extractors = [];

    public function register(ExtractorInterface $extractor): void
    {
        $this->extractors[$extractor->getName()] = $extractor;
    }

    /**
     * @return ExtractorInterface[]
     */
    public function all(): array
    {
        return $this->extractors;
    }

    public function get(string $name): ?ExtractorInterface
    {
        return $this->extractors[$name] ?? null;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->extractors);
    }
}
