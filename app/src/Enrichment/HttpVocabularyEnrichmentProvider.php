<?php

declare(strict_types=1);

namespace App\Enrichment;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpVocabularyEnrichmentProvider implements VocabularyEnrichmentProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $vocabularyEnrichmentBaseUrl,
        private float $vocabularyEnrichmentTimeout,
    ) {
    }

    public function enrich(VocabularyEnrichmentRequest $request): VocabularyEnrichmentResult
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->vocabularyEnrichmentBaseUrl, '/').'/enrich', [
                'json' => [
                    'lemma' => $request->lemma,
                    'part_of_speech' => $request->partOfSpeech,
                    'original_form' => $request->originalForm,
                    'context_sentence' => $request->contextSentence,
                    'source_language' => $request->sourceLanguage,
                    'target_language' => $request->targetLanguage,
                ],
                'timeout' => $this->vocabularyEnrichmentTimeout,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new VocabularyEnrichmentException(sprintf('AI enrichment provider returned HTTP %d.', $statusCode));
            }

            $payload = $response->toArray(false);
        } catch (VocabularyEnrichmentException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new VocabularyEnrichmentException('AI enrichment provider request failed: '.$exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            throw new VocabularyEnrichmentException('AI enrichment provider returned an invalid response: '.$exception->getMessage(), 0, $exception);
        }

        return $this->mapPayload($payload);
    }

    /**
     * @param array<mixed> $payload
     */
    private function mapPayload(array $payload): VocabularyEnrichmentResult
    {
        foreach (['translation_pl', 'definition_en', 'meaning_in_context', 'simple_example'] as $field) {
            if (!array_key_exists($field, $payload) || !is_string($payload[$field])) {
                throw new VocabularyEnrichmentException(sprintf('AI enrichment response is missing "%s".', $field));
            }
        }

        foreach (['cefr_level', 'provider', 'model', 'prompt_version'] as $field) {
            if (array_key_exists($field, $payload) && !is_string($payload[$field]) && $payload[$field] !== null) {
                throw new VocabularyEnrichmentException(sprintf('AI enrichment response field "%s" has invalid type.', $field));
            }
        }

        return new VocabularyEnrichmentResult(
            translationPl: $payload['translation_pl'],
            definitionEn: $payload['definition_en'],
            meaningInContext: $payload['meaning_in_context'],
            simpleExample: $payload['simple_example'],
            cefrLevel: $payload['cefr_level'] ?? null,
            provider: $payload['provider'] ?? null,
            model: $payload['model'] ?? null,
            promptVersion: $payload['prompt_version'] ?? null,
        );
    }
}
