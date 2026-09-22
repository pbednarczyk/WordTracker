<?php

declare(strict_types=1);

namespace App\Enrichment;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpAsyncEnrichmentGateway implements AsyncEnrichmentGatewayInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $nlpBaseUrl,
        private string $enrichmentInternalToken,
    ) {}

    public function prepare(array $request): array { return $this->call('prepare', $request); }
    public function evaluate(array $request): array { return $this->call('evaluate', $request); }
    public function publish(array $job): void
    {
        $response = $this->call('publish', $job);
        if (($response['job_id'] ?? null) !== $job['job_id'] || ($response['status'] ?? null) !== 'queued') {
            throw new VocabularyEnrichmentException('Publication was not confirmed.');
        }
    }

    private function call(string $operation, array $body): array
    {
        if ($this->enrichmentInternalToken === '') {
            throw new VocabularyEnrichmentException('Internal enrichment is not configured.');
        }
        try {
            return $this->httpClient->request('POST', rtrim($this->nlpBaseUrl, '/').'/internal/enrichment/'.$operation, [
                'headers' => ['X-Enrichment-Token' => $this->enrichmentInternalToken],
                'json' => $body, 'timeout' => 20, 'max_duration' => 25,
            ])->toArray();
        } catch (\Throwable $exception) {
            throw new VocabularyEnrichmentException('Internal enrichment service failed.', 0, $exception);
        }
    }
}
