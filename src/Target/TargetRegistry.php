<?php

declare(strict_types=1);

namespace MageContext\Target;

class TargetRegistry
{
    /** @var array<string, TargetInterface> */
    private array $targets = [];

    public function register(TargetInterface $target): void
    {
        $this->targets[$target->getName()] = $target;
    }

    /**
     * Get a target by name.
     */
    public function get(string $name): ?TargetInterface
    {
        return $this->targets[$name] ?? null;
    }

    /**
     * Auto-detect the best target for a given repo path.
     * Returns the first specific target that matches, or the generic fallback.
     */
    public function detect(string $repoPath): TargetInterface
    {
        foreach ($this->targets as $target) {
            if ($target->getName() === 'generic') {
                continue;
            }
            if ($target->detect($repoPath)) {
                return $target;
            }
        }

        // Fallback to generic
        return $this->targets['generic'] ?? new GenericTarget();
    }

    /**
     * @return array<string, TargetInterface>
     */
    public function all(): array
    {
        return $this->targets;
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->targets);
    }
}
