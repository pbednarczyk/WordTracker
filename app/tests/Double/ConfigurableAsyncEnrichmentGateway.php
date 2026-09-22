<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Enrichment\AsyncEnrichmentGatewayInterface;

final class ConfigurableAsyncEnrichmentGateway implements AsyncEnrichmentGatewayInterface
{
    public static array $published = [];
    public static array $evaluated = [];
    public static ?\Closure $onPublish = null;
    public static ?\Closure $onPrepare = null;
    public static ?\Closure $onEvaluate = null;
    public static bool $failPublish = false;
    public static int $sequence = 0;

    public static function message(): array
    {
        return ['schema_version' => 1, 'type' => 'llm.generate',
            'job_id' => sprintf('00000000-0000-4000-8000-%012d', ++self::$sequence),
            'created_at' => '2026-09-23T00:00:00Z', 'model' => 'qwen3:14b',
            'prompt' => 'mocked NLP prompt', 'format' => ['type' => 'object'], 'options' => ['temperature' => 0.2]];
    }

    public static function content(): array
    {
        return ['translation_pl' => 'gotowość', 'definition_en' => 'being ready',
            'meaning_in_context' => 'readiness to help', 'simple_example' => 'Her willingness helped.',
            'cefr_level' => 'B2', 'provider' => 'ollama', 'model' => 'qwen3:14b', 'prompt_version' => 'word-enrichment-v4'];
    }

    public function prepare(array $request): array
    {
        if (self::$onPrepare) { (self::$onPrepare)($request); }
        return ['job' => self::message(), 'provider' => 'ollama', 'prompt_version' => 'word-enrichment-v4'];
    }

    public function evaluate(array $request): array
    {
        self::$evaluated[] = $request;
        if (self::$onEvaluate) { return (self::$onEvaluate)($request); }
        return ['outcome' => 'COMPLETED', 'enrichment' => self::content()];
    }

    public function publish(array $job): void
    {
        self::$published[] = $job;
        if (self::$onPublish) { (self::$onPublish)($job); }
        if (self::$failPublish) { throw new \RuntimeException('broker unavailable'); }
    }

    public static function reset(): void
    {
        self::$published = self::$evaluated = [];
        self::$onPublish = self::$onPrepare = self::$onEvaluate = null;
        self::$failPublish = false;
        self::$sequence = 0;
    }
}
