<?php

declare(strict_types=1);

namespace App\Enrichment;

interface VocabularyEnrichmentProviderInterface
{
    public function enrich(VocabularyEnrichmentRequest $request): VocabularyEnrichmentResult;
}
