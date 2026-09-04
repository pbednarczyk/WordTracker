<?php

declare(strict_types=1);

namespace App\Enrichment;

final readonly class VocabularyEnrichmentResult
{
    private const ALLOWED_CEFR_LEVELS = ['A1' => true, 'A2' => true, 'B1' => true, 'B2' => true, 'C1' => true, 'C2' => true];

    public string $translationPl;
    public string $definitionEn;
    public string $meaningInContext;
    public string $simpleExample;
    public ?string $cefrLevel;
    public ?string $provider;
    public ?string $model;
    public ?string $promptVersion;

    public function __construct(
        string $translationPl,
        string $definitionEn,
        string $meaningInContext,
        string $simpleExample,
        ?string $cefrLevel = null,
        ?string $provider = null,
        ?string $model = null,
        ?string $promptVersion = null,
    ) {
        $this->translationPl = self::required($translationPl, 'translation_pl');
        $this->definitionEn = self::required($definitionEn, 'definition_en');
        $this->meaningInContext = self::required($meaningInContext, 'meaning_in_context');
        $this->simpleExample = self::required($simpleExample, 'simple_example');
        $this->cefrLevel = self::cefr($cefrLevel);
        $this->provider = self::optional($provider);
        $this->model = self::optional($model);
        $this->promptVersion = self::optional($promptVersion);
    }

    private static function required(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new VocabularyEnrichmentException(sprintf('AI enrichment response has empty "%s".', $field));
        }

        return $trimmed;
    }

    private static function optional(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function cefr(?string $value): ?string
    {
        $trimmed = self::optional($value);
        if ($trimmed === null) {
            return null;
        }

        $level = strtoupper($trimmed);
        if (!isset(self::ALLOWED_CEFR_LEVELS[$level])) {
            throw new VocabularyEnrichmentException(sprintf('AI enrichment response has invalid CEFR level "%s".', $trimmed));
        }

        return $level;
    }
}
