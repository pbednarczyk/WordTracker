<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AnalyzePublicationHandler;
use App\Application\PublicationAnalysisException;
use App\Application\PublicationVocabularyExporter;
use App\Application\VocabularyStatusManager;
use App\Entity\Publication;
use App\Entity\VocabularyItem;
use App\Enum\VocabularyStatus;
use App\Form\Model\PublicationInput;
use App\Form\PublicationFormType;
use App\Nlp\TextAnalyzerException;
use App\Repository\PublicationRepository;
use App\Repository\PublicationVocabularyRepository;
use App\Repository\VocabularyOccurrenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationRepository $publicationRepository,
        private readonly PublicationVocabularyRepository $publicationVocabularyRepository,
        private readonly VocabularyOccurrenceRepository $vocabularyOccurrenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalyzePublicationHandler $analyzePublication,
        private readonly VocabularyStatusManager $vocabularyStatusManager,
        private readonly PublicationVocabularyExporter $publicationVocabularyExporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/publications', name: 'publication_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('publication/index.html.twig', [
            'publications' => $this->publicationRepository->findAllOrderedByCreatedAt(),
        ]);
    }

    #[Route('/publications/new', name: 'publication_new', methods: ['GET'])]
    public function new(): Response
    {
        $form = $this->createForm(PublicationFormType::class, new PublicationInput(), [
            'action' => $this->generateUrl('publication_create'),
        ]);

        return $this->render('publication/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/publications', name: 'publication_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $input = new PublicationInput();
        $form = $this->createForm(PublicationFormType::class, $input, [
            'action' => $this->generateUrl('publication_create'),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('publication/new.html.twig', [
                'form' => $form->createView(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $publication = new Publication(
            title: trim((string) $input->title),
            type: $input->type,
            language: trim((string) $input->language),
            author: $this->blankToNull($input->author),
            rawText: trim((string) $input->rawText),
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        $this->addFlash('success', 'Publication created.');

        return $this->redirectToRoute('publication_show', [
            'id' => $publication->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/publications/{id}', name: 'publication_show', methods: ['GET'])]
    public function show(Publication $publication, Request $request): Response
    {
        $statusFilter = $this->parseOptionalStatus((string) $request->query->get('status', ''));
        $searchQuery = trim((string) $request->query->get('q', ''));
        $vocabulary = $this->publicationVocabularyRepository->findForPublicationFiltered(
            publication: $publication,
            status: $statusFilter,
            query: $searchQuery,
        );
        $coverageStats = $this->publicationVocabularyRepository->getCoverageStats($publication);

        return $this->render('publication/show.html.twig', [
            'publication' => $publication,
            'vocabulary' => $vocabulary,
            'summary' => $this->buildSummary($coverageStats),
            'filters' => [
                'status' => $statusFilter?->value ?? 'ALL',
                'q' => $searchQuery,
            ],
            'textPreview' => $this->preview($publication->getRawText()),
        ]);
    }

    #[Route('/publications/{id}/text', name: 'publication_text', methods: ['GET'])]
    public function text(Publication $publication): Response
    {
        return $this->render('publication/text.html.twig', [
            'publication' => $publication,
            'text' => trim((string) $publication->getRawText()),
        ]);
    }

    #[Route('/publications/{id}/analyze', name: 'publication_analyze', methods: ['POST'])]
    public function analyze(Publication $publication, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->analyzeCsrfTokenId($publication), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            ($this->analyzePublication)($publication);
            $this->addFlash('success', 'Publication analyzed successfully.');
        } catch (PublicationAnalysisException|TextAnalyzerException $exception) {
            $this->logger->error('Publication analysis failed.', [
                'publication_id' => $publication->getId(),
                'exception' => $exception,
            ]);

            $message = 'Analysis failed.';
            if ((bool) $this->getParameter('kernel.debug')) {
                $message .= ' '.$exception->getMessage();
            }

            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('publication_show', [
            'id' => $publication->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/publications/{id}/vocabulary/export.csv', name: 'publication_vocabulary_export_csv', methods: ['GET'])]
    public function exportVocabularyCsv(Publication $publication, Request $request): Response
    {
        $statusFilter = $this->parseOptionalStatus((string) $request->query->get('status', ''));
        $searchQuery = trim((string) $request->query->get('q', ''));

        return new Response($this->publicationVocabularyExporter->csv($publication, $statusFilter, $searchQuery), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->publicationVocabularyExporter->filename($publication, 'csv'),
            ),
        ]);
    }

    #[Route('/publications/{id}/vocabulary/export.xlsx', name: 'publication_vocabulary_export_xlsx', methods: ['GET'])]
    public function exportVocabularyXlsx(Publication $publication, Request $request): Response
    {
        $statusFilter = $this->parseOptionalStatus((string) $request->query->get('status', ''));
        $searchQuery = trim((string) $request->query->get('q', ''));

        return new Response($this->publicationVocabularyExporter->xlsx($publication, $statusFilter, $searchQuery), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->publicationVocabularyExporter->filename($publication, 'xlsx'),
            ),
        ]);
    }

    #[Route('/vocabulary/{id}', name: 'vocabulary_show', methods: ['GET'])]
    public function showVocabulary(VocabularyItem $item): Response
    {
        return $this->render('vocabulary/show.html.twig', [
            'item' => $item,
            'occurrences' => $this->vocabularyOccurrenceRepository->findForVocabularyItem($item),
            'summary' => $this->vocabularyOccurrenceRepository->getSummaryForVocabularyItem($item),
        ]);
    }

    #[Route('/vocabulary/{id}/status', name: 'vocabulary_status_update', methods: ['POST'])]
    public function updateVocabularyStatus(VocabularyItem $item, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->vocabularyStatusCsrfTokenId($item), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = VocabularyStatus::tryFrom((string) $request->request->get('status'));
        if ($status === null) {
            $this->addFlash('error', 'Invalid vocabulary status.');

            return $this->redirectAfterVocabularyStatusUpdate($request, $item);
        }

        $this->vocabularyStatusManager->updateOne($item, $status);
        $this->addFlash('success', sprintf('"%s" marked as %s.', $item->getLemma(), $status->value));

        return $this->redirectAfterVocabularyStatusUpdate($request, $item);
    }

    #[Route('/vocabulary/bulk-status', name: 'vocabulary_bulk_status_update', methods: ['POST'])]
    public function bulkUpdateVocabularyStatus(Request $request): RedirectResponse
    {
        $publicationId = (string) $request->request->get('publicationId', '');
        if (!$this->isCsrfTokenValid($this->bulkVocabularyStatusCsrfTokenId($publicationId), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = VocabularyStatus::tryFrom((string) $request->request->get('status'));
        if ($status === null) {
            $this->addFlash('error', 'Invalid vocabulary status.');

            return $this->redirectToPublicationFromRequest($request);
        }

        $ids = $request->request->all('ids');
        if ($ids === []) {
            $this->addFlash('error', 'No vocabulary items selected.');

            return $this->redirectToPublicationFromRequest($request);
        }

        $ids = array_map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT) ?: 0, $ids);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            $this->addFlash('error', 'Invalid vocabulary selection.');

            return $this->redirectToPublicationFromRequest($request);
        }

        try {
            $updated = $this->vocabularyStatusManager->updateManyByIds($ids, $status);
            $this->addFlash('success', sprintf('%d vocabulary items marked as %s.', $updated, $status->value));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Invalid vocabulary selection.');
        }

        return $this->redirectToPublicationFromRequest($request);
    }

    private function analyzeCsrfTokenId(Publication $publication): string
    {
        return 'analyze_publication_'.$publication->getId();
    }

    private function vocabularyStatusCsrfTokenId(VocabularyItem $item): string
    {
        return 'vocabulary_status_'.$item->getId();
    }

    private function bulkVocabularyStatusCsrfTokenId(string $publicationId): string
    {
        return 'vocabulary_bulk_status_'.$publicationId;
    }

    private function parseOptionalStatus(string $status): ?VocabularyStatus
    {
        if ($status === '' || strtoupper($status) === 'ALL') {
            return null;
        }

        return VocabularyStatus::tryFrom(strtoupper($status));
    }

    /**
     * @param array{
     *     uniqueTotal: int,
     *     uniqueKnown: int,
     *     uniqueUnknown: int,
     *     occurrencesTotal: int,
     *     occurrencesKnown: int,
     *     occurrencesUnknown: int
     * } $stats
     *
     * @return array<string, int|string>
     */
    private function buildSummary(array $stats): array
    {
        return [
            'uniqueVocabulary' => $stats['uniqueTotal'],
            'knownVocabulary' => $stats['uniqueKnown'],
            'unknownVocabulary' => $stats['uniqueUnknown'],
            'vocabularyOccurrences' => $stats['occurrencesTotal'],
            'knownOccurrences' => $stats['occurrencesKnown'],
            'unknownOccurrences' => $stats['occurrencesUnknown'],
            'vocabularyCoverage' => $this->formatPercentage($stats['uniqueKnown'], $stats['uniqueTotal']),
            'textCoverage' => $this->formatPercentage($stats['occurrencesKnown'], $stats['occurrencesTotal']),
        ];
    }

    private function formatPercentage(int $part, int $total): string
    {
        if ($total === 0) {
            return 'N/A';
        }

        return number_format(($part / $total) * 100, 1).'%';
    }

    private function redirectToPublicationFromRequest(Request $request): RedirectResponse
    {
        $publicationId = filter_var($request->request->get('publicationId'), FILTER_VALIDATE_INT);
        if ($publicationId === false || $publicationId === null) {
            return $this->redirectToRoute('publication_index', [], Response::HTTP_SEE_OTHER);
        }

        $parameters = ['id' => $publicationId];
        $status = (string) $request->request->get('currentStatus', '');
        if ($status !== '' && strtoupper($status) !== 'ALL') {
            $parameters['status'] = strtoupper($status);
        }

        $query = trim((string) $request->request->get('currentQuery', ''));
        if ($query !== '') {
            $parameters['q'] = $query;
        }

        return $this->redirectToRoute('publication_show', $parameters, Response::HTTP_SEE_OTHER);
    }

    private function redirectAfterVocabularyStatusUpdate(Request $request, VocabularyItem $item): RedirectResponse
    {
        if ((string) $request->request->get('redirectTo') === 'vocabulary') {
            return $this->redirectToRoute('vocabulary_show', [
                'id' => $item->getId(),
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToPublicationFromRequest($request);
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function preview(?string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        if (mb_strlen($normalized) <= 360) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 360).'...';
    }
}
