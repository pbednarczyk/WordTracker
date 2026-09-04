<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Enrichment\VocabularyEnrichmentException;
use App\Enrichment\VocabularyEnrichmentProviderInterface;
use App\Enrichment\VocabularyEnrichmentRequest;
use App\Enrichment\VocabularyEnrichmentResult;

final class ConfigurableVocabularyEnrichmentProvider implements VocabularyEnrichmentProviderInterface
{
    public static ?VocabularyEnrichmentResult $result = null;
    public static ?VocabularyEnrichmentException $exception = null;

    /**
     * @var list<VocabularyEnrichmentRequest>
     */
    public static array $requests = [];

    public function enrich(VocabularyEnrichmentRequest $request): VocabularyEnrichmentResult
    {
        self::$requests[] = $request;

        if (self::$exception !== null) {
            throw self::$exception;
        }

        if (self::$result === null) {
            throw new VocabularyEnrichmentException('No test enrichment result configured.');
        }

        return self::$result;
    }

    public static function reset(): void
    {
        self::$result = null;
        self::$exception = null;
        self::$requests = [];
    }
}
