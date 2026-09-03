<?php

declare(strict_types=1);

namespace App\Nlp;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpTextAnalyzer implements TextAnalyzerInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $nlpBaseUrl,
    ) {
    }

    public function analyze(string $text): TextAnalysis
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->nlpBaseUrl, '/').'/analyze', [
                'json' => ['text' => $text],
                'timeout' => 30.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new TextAnalyzerException(sprintf('NLP analyzer returned HTTP %d.', $statusCode));
            }

            $payload = $response->toArray(false);
        } catch (TextAnalyzerException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface $exception) {
            throw new TextAnalyzerException('NLP analyzer request failed: '.$exception->getMessage(), 0, $exception);
        } catch (\Throwable $exception) {
            throw new TextAnalyzerException('NLP analyzer returned an invalid response: '.$exception->getMessage(), 0, $exception);
        }

        return $this->mapPayload($payload);
    }

    /**
     * @param array<mixed> $payload
     */
    private function mapPayload(array $payload): TextAnalysis
    {
        foreach (['language', 'token_count', 'word_count', 'unique_lemma_count', 'tokens'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new TextAnalyzerException(sprintf('NLP analyzer response is missing "%s".', $field));
            }
        }

        if (!is_string($payload['language']) || !is_int($payload['token_count'])
            || !is_int($payload['word_count']) || !is_int($payload['unique_lemma_count'])
            || !is_array($payload['tokens'])) {
            throw new TextAnalyzerException('NLP analyzer response has invalid top-level field types.');
        }

        $tokens = [];
        foreach ($payload['tokens'] as $index => $tokenPayload) {
            if (!is_array($tokenPayload)) {
                throw new TextAnalyzerException(sprintf('NLP analyzer token %d is invalid.', $index));
            }

            $tokens[] = $this->mapToken($tokenPayload, $index);
        }

        return new TextAnalysis(
            language: $payload['language'],
            tokenCount: $payload['token_count'],
            wordCount: $payload['word_count'],
            uniqueLemmaCount: $payload['unique_lemma_count'],
            tokens: $tokens,
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private function mapToken(array $payload, int $index): AnalyzedToken
    {
        foreach (['text', 'lemma', 'pos', 'entity_type', 'sentence', 'position', 'is_proper_noun'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new TextAnalyzerException(sprintf('NLP analyzer token %d is missing "%s".', $index, $field));
            }
        }

        if (!is_string($payload['text']) || !is_string($payload['lemma']) || !is_string($payload['pos'])
            || (!is_string($payload['entity_type']) && $payload['entity_type'] !== null)
            || !is_string($payload['sentence']) || !is_int($payload['position'])
            || !is_bool($payload['is_proper_noun'])) {
            throw new TextAnalyzerException(sprintf('NLP analyzer token %d has invalid field types.', $index));
        }

        return new AnalyzedToken(
            text: $payload['text'],
            lemma: $payload['lemma'],
            pos: $payload['pos'],
            entityType: $payload['entity_type'],
            sentence: $payload['sentence'],
            position: $payload['position'],
            isProperNoun: $payload['is_proper_noun'],
        );
    }
}
