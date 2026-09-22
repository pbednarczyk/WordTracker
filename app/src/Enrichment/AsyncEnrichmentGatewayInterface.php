<?php

declare(strict_types=1);

namespace App\Enrichment;

interface AsyncEnrichmentGatewayInterface
{
    public function prepare(array $request): array;
    public function evaluate(array $request): array;
    public function publish(array $job): void;
}
