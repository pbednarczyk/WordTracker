<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enrichment\HttpVocabularyEnrichmentProvider;
use App\Enrichment\VocabularyEnrichmentRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpVocabularyEnrichmentProviderTest extends TestCase
{
    public function testSymfonyDoesNotSendOllamaModelToNlp(): void
    {
        $sentPayload = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sentPayload): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://nlp:8000/enrich', $url);
            $sentPayload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode([
                'translation_pl' => 'gotowosc',
                'definition_en' => 'the quality of being ready',
                'meaning_in_context' => 'readiness in this context',
                'simple_example' => 'Her willingness helped.',
                'cefr_level' => 'B2',
                'provider' => 'ollama',
                'model' => 'qwen3:14b',
                'prompt_version' => 'word-enrichment-v2',
            ], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]);
        });

        $provider = new HttpVocabularyEnrichmentProvider(
            httpClient: $client,
            vocabularyEnrichmentBaseUrl: 'http://nlp:8000',
            vocabularyEnrichmentTimeout: 120.0,
        );

        $result = $provider->enrich(new VocabularyEnrichmentRequest(
            lemma: 'willingness',
            partOfSpeech: 'NOUN',
            originalForm: 'willingness',
            contextSentence: 'His willingness to grow impressed everyone.',
            sourceLanguage: 'en',
            targetLanguage: 'pl',
        ));

        self::assertIsArray($sentPayload);
        self::assertArrayNotHasKey('model', $sentPayload);
        self::assertSame('qwen3:14b', $result->model);
    }
}
