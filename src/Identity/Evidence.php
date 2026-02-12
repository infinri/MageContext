<?php

declare(strict_types=1);

namespace MageContext\Identity;

/**
 * Evidence object attached to every derived fact in v2 outputs.
 *
 * Spec §2.2: "Any derived fact must include evidence."
 * If a fact has no evidence, it is not allowed in v2 outputs.
 */
class Evidence
{
    public const TYPE_XML = 'xml';
    public const TYPE_PHP_AST = 'php_ast';
    public const TYPE_COMPOSER = 'composer';
    public const TYPE_GIT = 'git';
    public const TYPE_INFERENCE = 'inference';
    public const TYPE_FILESYSTEM = 'filesystem';

    private string $type;
    private string $sourceFile;
    private ?int $lineStart;
    private ?int $lineEnd;
    private float $confidence;
    private string $notes;

    public function __construct(
        string $type,
        string $sourceFile,
        ?int $lineStart = null,
        ?int $lineEnd = null,
        float $confidence = 1.0,
        string $notes = ''
    ) {
        $this->type = $type;
        $this->sourceFile = $sourceFile;
        $this->lineStart = $lineStart;
        $this->lineEnd = $lineEnd;
        $this->confidence = max(0.0, min(1.0, $confidence));
        $this->notes = $notes;
    }

    /**
     * Create evidence from an XML source (di.xml, events.xml, module.xml, etc.).
     */
    public static function fromXml(string $sourceFile, string $notes = '', float $confidence = 1.0): self
    {
        return new self(self::TYPE_XML, $sourceFile, null, null, $confidence, $notes);
    }

    /**
     * Create evidence from PHP AST analysis.
     */
    public static function fromPhpAst(
        string $sourceFile,
        int $lineStart,
        ?int $lineEnd = null,
        string $notes = '',
        float $confidence = 1.0
    ): self {
        return new self(self::TYPE_PHP_AST, $sourceFile, $lineStart, $lineEnd, $confidence, $notes);
    }

    /**
     * Create evidence from composer.json / composer.lock.
     */
    public static function fromComposer(string $sourceFile, string $notes = '', float $confidence = 1.0): self
    {
        return new self(self::TYPE_COMPOSER, $sourceFile, null, null, $confidence, $notes);
    }

    /**
     * Create evidence from git data.
     */
    public static function fromGit(string $notes = '', float $confidence = 0.9): self
    {
        return new self(self::TYPE_GIT, '', null, null, $confidence, $notes);
    }

    /**
     * Create evidence from inference (lower confidence by default).
     */
    public static function fromInference(string $notes, float $confidence = 0.5): self
    {
        return new self(self::TYPE_INFERENCE, '', null, null, $confidence, $notes);
    }

    /**
     * Create evidence from filesystem observation.
     */
    public static function fromFilesystem(string $sourceFile, string $notes = '', float $confidence = 0.9): self
    {
        return new self(self::TYPE_FILESYSTEM, $sourceFile, null, null, $confidence, $notes);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSourceFile(): string
    {
        return $this->sourceFile;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /**
     * Serialize to array for JSON output.
     */
    public function toArray(): array
    {
        $arr = [
            'type' => $this->type,
            'source_file' => $this->sourceFile,
            'confidence' => $this->confidence,
        ];

        if ($this->lineStart !== null) {
            $arr['source_span'] = ['line_start' => $this->lineStart];
            if ($this->lineEnd !== null) {
                $arr['source_span']['line_end'] = $this->lineEnd;
            }
        }

        if ($this->notes !== '') {
            $arr['notes'] = $this->notes;
        }

        return $arr;
    }

    /**
     * Serialize a list of Evidence objects to array.
     *
     * @param Evidence[] $evidences
     * @return array[]
     */
    public static function listToArray(array $evidences): array
    {
        return array_map(fn(self $e) => $e->toArray(), $evidences);
    }

    /**
     * Compute aggregate confidence from multiple evidence objects.
     * Uses 1 - product(1 - confidence_i) formula.
     *
     * @param Evidence[] $evidences
     */
    public static function aggregateConfidence(array $evidences): float
    {
        if (empty($evidences)) {
            return 0.0;
        }

        $notConfidence = 1.0;
        foreach ($evidences as $e) {
            $notConfidence *= (1.0 - $e->getConfidence());
        }

        return round(1.0 - $notConfidence, 3);
    }
}
