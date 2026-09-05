<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AnalyzePublicationHandler;
use App\Application\EnrichPublicationVocabularyHandler;
use App\Application\LearningCardGenerationResult;
use App\Application\LearningCardGenerator;
use App\Application\PublicationAnalysisException;
use App\Application\PublicationVocabularyExporter;
use App\Application\VocabularyStatusManager;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\VocabularyStatus;
use App\Enrichment\VocabularyEnrichmentException;
use App\Form\Model\PublicationInput;
use App\Form\PublicationFormType;
use App\Nlp\TextAnalyzerException;
use App\Repository\PublicationRepository;
use App\Repository\LearningCardRepository;
use App\Repository\PublicationVocabularyQuery;
use App\Repository\PublicationVocabularyRepository;
use App\Repository\VocabularyOccurrenceRepository;
use App\Vocabulary\PartOfSpeech;
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
        private readonly EnrichPublicationVocabularyHandler $enrichPublicationVocabulary,
        private readonly LearningCardGenerator $learningCardGenerator,
        private readonly LearningCardRepository $learningCardRepository,
        private readonly VocabularyStatusManager $vocabularyStatusManager,
        private readonly PublicationVocabularyExporter $publicationVocabularyExporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/publications', name: 'publication_index', methods: ['GET'])]
    public function index(): Response
    {
        $publications = $this->publicationRepository->findAllOrderedByCreatedAt();

        return $this->render('publication/index.html.twig', [
            'publications' => $publications,
            'coverageByPublication' => $this->buildListCoverage($publications),
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
        $query = PublicationVocabularyQuery::fromParameters($request->query->all());
        $paginatedVocabulary = $this->publicationVocabularyRepository->findForPublication($publication, $query);
        $query = $query->withPage($paginatedVocabulary->page);
        $coverageStats = $this->publicationVocabularyRepository->getCoverageStats($publication);

        return $this->render('publication/show.html.twig', [
            'publication' => $publication,
            'vocabulary' => $paginatedVocabulary->items,
            'cardCounts' => $this->learningCardRepository->countByPublicationVocabulary($paginatedVocabulary->items),
            'pagination' => $paginatedVocabulary,
            'summary' => $this->buildSummary($coverageStats),
            'filters' => $this->tableFilters($query),
            'sortLinks' => $this->sortLinks($publication, $query),
            'paginationPages' => $this->paginationPages($paginatedVocabulary->page, $paginatedVocabulary->totalPages),
            'perPageOptions' => PublicationVocabularyQuery::PER_PAGE_OPTIONS,
            'posOptions' => PartOfSpeech::VALUES,
            'exportParams' => ['id' => $publication->getId()] + $query->toUrlParameters(includePagination: false),
            'hiddenTableState' => $query->toHiddenFields(),
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
        $query = PublicationVocabularyQuery::fromParameters($request->query->all());

        return new Response($this->publicationVocabularyExporter->csv($publication, $query), Response::HTTP_OK, [
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
        $query = PublicationVocabularyQuery::fromParameters($request->query->all());

        return new Response($this->publicationVocabularyExporter->xlsx($publication, $query), Response::HTTP_OK, [
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
        $publicationVocabulary = $this->publicationVocabularyRepository->findForVocabularyItemWithEnrichment($item);

        return $this->render('vocabulary/show.html.twig', [
            'item' => $item,
            'occurrences' => $this->vocabularyOccurrenceRepository->findForVocabularyItem($item),
            'publicationVocabulary' => $publicationVocabulary,
            'cardCounts' => $this->learningCardRepository->countByPublicationVocabulary($publicationVocabulary),
            'summary' => $this->vocabularyOccurrenceRepository->getSummaryForVocabularyItem($item),
        ]);
    }

    #[Route('/publication-vocabulary/{id}/enrichment', name: 'publication_vocabulary_enrich', methods: ['POST'])]
    public function enrichPublicationVocabulary(PublicationVocabulary $publicationVocabulary, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->publicationVocabularyEnrichmentCsrfTokenId($publicationVocabulary), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            ($this->enrichPublicationVocabulary)($publicationVocabulary);
            $this->addFlash('success', sprintf('Enrichment generated for "%s".', $publicationVocabulary->getVocabularyItem()->getLemma()));
        } catch (VocabularyEnrichmentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('vocabulary_show', [
            'id' => $publicationVocabulary->getVocabularyItem()->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/vocabulary/bulk-enrichment', name: 'vocabulary_bulk_enrich', methods: ['POST'])]
    public function bulkEnrichVocabulary(Request $request): RedirectResponse
    {
        $publicationId = (string) $request->request->get('publicationId', '');
        if (!$this->isCsrfTokenValid($this->bulkVocabularyEnrichmentCsrfTokenId($publicationId), (string) $request->request->get('enrichmentToken'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = array_map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT) ?: 0, $request->request->all('ids'));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            $this->addFlash('error', 'No vocabulary items selected.');

            return $this->redirectToPublicationFromRequest($request);
        }

        if (count($ids) > 50) {
            $this->addFlash('error', 'Bulk enrichment is limited to 50 vocabulary items at a time.');

            return $this->redirectToPublicationFromRequest($request);
        }

        $publication = $this->publicationRepository->find(filter_var($publicationId, FILTER_VALIDATE_INT) ?: 0);
        if (!$publication instanceof Publication) {
            $this->addFlash('error', 'Invalid publication selection.');

            return $this->redirectToPublicationFromRequest($request);
        }

        $successes = 0;
        $failures = [];
        $publicationVocabularyRows = $this->publicationVocabularyRepository->findForPublicationAndVocabularyItemIds($publication, $ids);
        foreach ($publicationVocabularyRows as $publicationVocabulary) {
            try {
                ($this->enrichPublicationVocabulary)($publicationVocabulary);
                ++$successes;
            } catch (VocabularyEnrichmentException $exception) {
                $failures[] = $publicationVocabulary->getVocabularyItem()->getLemma().': '.$exception->getMessage();
            }
        }

        if ($successes > 0) {
            $this->addFlash('success', sprintf('%d enrichment%s generated.', $successes, $successes === 1 ? '' : 's'));
        }

        if ($failures !== []) {
            $this->addFlash('error', 'Some enrichments failed: '.implode('; ', array_slice($failures, 0, 3)));
        }

        return $this->redirectToPublicationFromRequest($request);
    }

    #[Route('/publication-vocabulary/{id}/learning-cards/generate', name: 'publication_vocabulary_learning_cards_generate', methods: ['POST'])]
    public function generateLearningCards(PublicationVocabulary $publicationVocabulary, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->learningCardsCsrfTokenId($publicationVocabulary), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $this->learningCardGenerator->generate($publicationVocabulary);
        $this->addLearningCardGenerationFlash($result);

        return $this->redirectToRoute('vocabulary_show', [
            'id' => $publicationVocabulary->getVocabularyItem()->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/publications/{id}/learning-cards/generate-selected', name: 'publication_learning_cards_generate_selected', methods: ['POST'])]
    public function bulkGenerateLearningCards(Publication $publication, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->bulkLearningCardsCsrfTokenId((string) $publication->getId()), (string) $request->request->get('learningCardsToken'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = array_map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT) ?: 0, $request->request->all('ids'));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            $this->addFlash('error', 'No vocabulary items selected.');

            return $this->redirectToPublicationFromRequest($request);
        }

        if (count($ids) > 100) {
            $this->addFlash('error', 'Learning card generation is limited to 100 vocabulary items at a time.');

            return $this->redirectToPublicationFromRequest($request);
        }

        $publicationVocabularyRows = $this->publicationVocabularyRepository->findForPublicationAndVocabularyItemIds($publication, $ids);
        $result = $this->learningCardGenerator->generateMany($publicationVocabularyRows);
        $this->addLearningCardGenerationFlash($result);

        return $this->redirectToPublicationFromRequest($request);
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

    private function publicationVocabularyEnrichmentCsrfTokenId(PublicationVocabulary $publicationVocabulary): string
    {
        return 'publication_vocabulary_enrichment_'.$publicationVocabulary->getId();
    }

    private function learningCardsCsrfTokenId(PublicationVocabulary $publicationVocabulary): string
    {
        return 'publication_vocabulary_learning_cards_'.$publicationVocabulary->getId();
    }

    private function bulkVocabularyEnrichmentCsrfTokenId(string $publicationId): string
    {
        return 'vocabulary_bulk_enrichment_'.$publicationId;
    }

    private function bulkLearningCardsCsrfTokenId(string $publicationId): string
    {
        return 'publication_learning_cards_'.$publicationId;
    }

    private function addLearningCardGenerationFlash(LearningCardGenerationResult $result): void
    {
        if ($result->created > 0) {
            $this->addFlash('success', sprintf('%d learning card%s generated.', $result->created, $result->created === 1 ? '' : 's'));
        }

        if ($result->created === 0 && $result->existing > 0) {
            $this->addFlash('success', 'Learning cards already exist.');
        }

        if ($result->skippedWithoutEnrichment > 0) {
            $this->addFlash('error', sprintf('Skipped without enrichment: %d.', $result->skippedWithoutEnrichment));
        }

        if ($result->skippedCloze > 0) {
            $this->addFlash('error', sprintf('Skipped cloze cards: %d.', $result->skippedCloze));
        }
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
     * @return array<string, bool|float|int|string|null>
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
            'vocabularyCoveragePercent' => $this->percentage($stats['uniqueKnown'], $stats['uniqueTotal']),
            'vocabularyCoverageLevel' => $this->coverageLevel($stats['uniqueKnown'], $stats['uniqueTotal']),
            'textCoverage' => $this->formatPercentage($stats['occurrencesKnown'], $stats['occurrencesTotal']),
            'textCoveragePercent' => $this->percentage($stats['occurrencesKnown'], $stats['occurrencesTotal']),
            'completed' => $stats['uniqueTotal'] > 0 && $stats['uniqueKnown'] === $stats['uniqueTotal'],
        ];
    }

    private function formatPercentage(int $part, int $total): string
    {
        $percentage = $this->percentage($part, $total);
        if ($percentage === null) {
            return 'N/A';
        }

        return number_format($percentage, 1).'%';
    }

    private function percentage(int $part, int $total): ?float
    {
        if ($total === 0) {
            return null;
        }

        return round(($part / $total) * 100, 1);
    }

    private function coverageLevel(int $part, int $total): string
    {
        $percentage = $this->percentage($part, $total);
        if ($percentage === null) {
            return 'none';
        }

        return match (true) {
            $percentage >= 100.0 => 'complete',
            $percentage >= 80.0 => 'high',
            $percentage >= 50.0 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param list<Publication> $publications
     *
     * @return array<int, array<string, int|string|float|bool|null>>
     */
    private function buildListCoverage(array $publications): array
    {
        $statsByPublication = $this->publicationVocabularyRepository->getCoverageStatsForPublications($publications);
        $coverage = [];

        foreach ($statsByPublication as $publicationId => $stats) {
            $coverage[$publicationId] = $this->buildSummary($stats);
        }

        return $coverage;
    }

    private function redirectToPublicationFromRequest(Request $request): RedirectResponse
    {
        $publicationId = filter_var($request->request->get('publicationId'), FILTER_VALIDATE_INT);
        if ($publicationId === false || $publicationId === null) {
            return $this->redirectToRoute('publication_index', [], Response::HTTP_SEE_OTHER);
        }

        $parameters = ['id' => $publicationId];
        $parameters += $this->nonDefaultTableParameters(PublicationVocabularyQuery::fromParameters([
            'q' => $request->request->get('currentQuery', ''),
            'status' => $request->request->get('currentStatus', 'all'),
            'enriched' => $request->request->get('currentEnriched', 'all'),
            'pos' => $request->request->get('currentPos', 'all'),
            'sort' => $request->request->get('currentSort', PublicationVocabularyQuery::DEFAULT_SORT),
            'direction' => $request->request->get('currentDirection', PublicationVocabularyQuery::DEFAULT_DIRECTION),
            'page' => $request->request->get('currentPage', PublicationVocabularyQuery::DEFAULT_PAGE),
            'perPage' => $request->request->get('currentPerPage', PublicationVocabularyQuery::DEFAULT_PER_PAGE),
        ]));

        return $this->redirectToRoute('publication_show', $parameters, Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, string|int>
     */
    private function tableFilters(PublicationVocabularyQuery $query): array
    {
        return [
            'q' => $query->search,
            'status' => strtolower($query->status?->value ?? 'all'),
            'enriched' => $query->enriched,
            'pos' => $query->partOfSpeech ?? 'all',
            'sort' => $query->sort,
            'direction' => $query->direction,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function nonDefaultTableParameters(PublicationVocabularyQuery $query): array
    {
        $parameters = [];
        if ($query->search !== '') {
            $parameters['q'] = $query->search;
        }
        if ($query->status !== null) {
            $parameters['status'] = strtolower($query->status->value);
        }
        if ($query->enriched !== PublicationVocabularyQuery::ENRICHED_ALL) {
            $parameters['enriched'] = $query->enriched;
        }
        if ($query->partOfSpeech !== null) {
            $parameters['pos'] = $query->partOfSpeech;
        }
        if ($query->sort !== PublicationVocabularyQuery::DEFAULT_SORT) {
            $parameters['sort'] = $query->sort;
        }
        if ($query->direction !== PublicationVocabularyQuery::defaultDirectionForSort($query->sort)) {
            $parameters['direction'] = $query->direction;
        }
        if ($query->page !== PublicationVocabularyQuery::DEFAULT_PAGE) {
            $parameters['page'] = $query->page;
        }
        if ($query->perPage !== PublicationVocabularyQuery::DEFAULT_PER_PAGE) {
            $parameters['perPage'] = $query->perPage;
        }

        return $parameters;
    }

    /**
     * @return array<string, array{params: array<string, string|int>, direction: string}>
     */
    private function sortLinks(Publication $publication, PublicationVocabularyQuery $query): array
    {
        $links = [];
        foreach (PublicationVocabularyQuery::SORTS as $sort) {
            $direction = $query->sort === $sort
                ? ($query->direction === PublicationVocabularyQuery::DIRECTION_ASC ? PublicationVocabularyQuery::DIRECTION_DESC : PublicationVocabularyQuery::DIRECTION_ASC)
                : PublicationVocabularyQuery::defaultDirectionForSort($sort);

            $links[$sort] = [
                'params' => ['id' => $publication->getId()] + (new PublicationVocabularyQuery(
                    search: $query->search,
                    status: $query->status,
                    enriched: $query->enriched,
                    partOfSpeech: $query->partOfSpeech,
                    sort: $sort,
                    direction: $direction,
                    page: 1,
                    perPage: $query->perPage,
                ))->toUrlParameters(),
                'direction' => $direction,
            ];
        }

        return $links;
    }

    /**
     * @return list<int|string>
     */
    private function paginationPages(int $page, int $totalPages): array
    {
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $pages = [1];
        $start = max(2, $page - 1);
        $end = min($totalPages - 1, $page + 1);

        if ($start > 2) {
            $pages[] = 'gap-left';
        }

        for ($number = $start; $number <= $end; ++$number) {
            $pages[] = $number;
        }

        if ($end < $totalPages - 1) {
            $pages[] = 'gap-right';
        }

        $pages[] = $totalPages;

        return $pages;
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
