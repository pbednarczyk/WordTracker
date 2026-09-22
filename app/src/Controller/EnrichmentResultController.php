<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AsyncEnrichmentWorkflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EnrichmentResultController
{
    public function __construct(private AsyncEnrichmentWorkflow $workflow, private string $enrichmentInternalToken) {}

    #[Route('/internal/enrichment/results', name: 'internal_enrichment_result', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->enrichmentInternalToken === '') { return new JsonResponse(['error' => 'Not configured.'], 503); }
        if (!hash_equals($this->enrichmentInternalToken, $request->headers->get('X-Enrichment-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid internal token.'], 401);
        }
        try { $result = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return new JsonResponse(['error' => 'Invalid JSON.'], 400); }
        if (!is_array($result) || !AsyncEnrichmentWorkflow::validUuid($result['job_id'] ?? null)
            || $request->headers->get('X-LLM-Correlation-ID') !== $result['job_id']
            || !$this->validEnvelope($result)
            || ($result['schema_version'] ?? null) !== 1 || ($result['type'] ?? null) !== 'llm.generate.result'
            || !is_string($result['model'] ?? null) || trim($result['model']) === ''
            || !in_array($result['status'] ?? null, ['completed', 'failed'], true)
            || !array_key_exists('response', $result) || !array_key_exists('error', $result)
            || (($result['status'] === 'completed') && (!is_string($result['response']) || $result['error'] !== null))
            || (($result['status'] === 'failed') && ($result['response'] !== null || !is_array($result['error'])))
        ) { return new JsonResponse(['error' => 'Invalid or uncorrelated result.'], 400); }
        try {
            return new JsonResponse(['disposition' => $this->workflow->accept($result)]);
        } catch (\Throwable) {
            // No success/ACK for a failed transaction or unavailable semantics service.
            return new JsonResponse(['error' => 'Result application unavailable.'], 503);
        }
    }
    private function validEnvelope(array $result): bool
    {
        $keys = array_keys($result);
        $expected = ['schema_version', 'type', 'job_id', 'model', 'status', 'response', 'error', 'worker', 'completed_at'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || !is_string($result['worker']) || trim($result['worker']) === ''
            || !is_string($result['completed_at'])
            || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+]00:00)$/D', $result['completed_at'])) {
            return false;
        }
        try { new \DateTimeImmutable($result['completed_at']); }
        catch (\Exception) { return false; }
        if (($result['status'] ?? null) === 'failed') {
            $error = $result['error'];
            if (!is_array($error) || count($error) !== 2) { return false; }
            foreach (['code', 'message'] as $field) {
                if (!is_string($error[$field] ?? null) || trim($error[$field]) === '') { return false; }
            }
        }
        return true;
    }
}
