<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\SmartStudyQueueBuilder;
use App\Application\RecordLearningReviewHandler;
use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Enum\LearningCardType;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use App\Repository\LearningCardQuery;
use App\Repository\LearningCardRepository;
use App\Repository\LearningReviewQuery;
use App\Repository\LearningReviewRepository;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class LearningController extends AbstractController
{
    private const STUDY_IDS_KEY = 'learning_study_ids';
    private const STUDY_INDEX_KEY = 'learning_study_index';
    private const STUDY_REVEALED_KEY = 'learning_study_revealed';
    private const STUDY_SESSION_ID_KEY = 'learning_study_session_id';
    private const STUDY_STARTED_AT_KEY = 'learning_study_started_at';

    public function __construct(
        private readonly LearningCardRepository $learningCardRepository,
        private readonly LearningReviewRepository $learningReviewRepository,
        private readonly PublicationRepository $publicationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SmartStudyQueueBuilder $studyQueueBuilder,
        private readonly RecordLearningReviewHandler $recordLearningReviewHandler,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/learning/cards', name: 'learning_cards', methods: ['GET'])]
    public function cards(Request $request): Response
    {
        $query = LearningCardQuery::fromParameters($request->query->all());
        $result = $this->learningCardRepository->findPaginated($query);
        $query = $query->withPage($result->page);

        return $this->render('learning/cards.html.twig', [
            'cards' => $result->items,
            'pagination' => $result,
            'filters' => $query->toUrlParameters(),
            'sortLinks' => $this->sortLinks($query),
            'paginationPages' => $this->paginationPages($result->page, $result->totalPages),
            'perPageOptions' => LearningCardQuery::PER_PAGE_OPTIONS,
            'types' => LearningCardType::cases(),
            'statuses' => VocabularyStatus::cases(),
            'publications' => $this->publicationRepository->findAllOrderedByCreatedAt(),
            'reviewStats' => $this->learningReviewRepository->statsByLearningCards($result->items),
        ]);
    }

    #[Route('/learning/reviews', name: 'learning_reviews', methods: ['GET'])]
    public function reviews(Request $request): Response
    {
        $query = LearningReviewQuery::fromParameters($request->query->all());
        $result = $this->learningReviewRepository->findPaginated($query);
        $query = $query->withPage($result->page);

        return $this->render('learning/reviews.html.twig', [
            'reviews' => $result->items,
            'pagination' => $result,
            'filters' => $query->toUrlParameters(),
            'paginationPages' => $this->paginationPages($result->page, $result->totalPages),
            'perPageOptions' => LearningReviewQuery::PER_PAGE_OPTIONS,
            'types' => LearningCardType::cases(),
            'ratings' => ReviewRating::cases(),
            'publications' => $this->publicationRepository->findAllOrderedByCreatedAt(),
        ]);
    }

    #[Route('/learning/cards/{id}/activate', name: 'learning_card_activate', methods: ['POST'])]
    public function activate(LearningCard $card, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->cardCsrfTokenId($card), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $card->activate();
        $this->entityManager->flush();
        $this->addFlash('success', 'Learning card activated.');

        return $this->redirectToRoute('learning_cards', $this->redirectParameters($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/learning/cards/{id}/deactivate', name: 'learning_card_deactivate', methods: ['POST'])]
    public function deactivate(LearningCard $card, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->cardCsrfTokenId($card), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $card->deactivate();
        $this->entityManager->flush();
        $this->addFlash('success', 'Learning card deactivated.');

        return $this->redirectToRoute('learning_cards', $this->redirectParameters($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/learning/study', name: 'learning_study', methods: ['GET'])]
    public function study(Request $request): Response
    {
        $session = $request->getSession();
        if ($this->shouldStartStudy($request, $session)) {
            $candidates = $this->learningCardRepository->findStudyCandidates(LearningCardQuery::fromParameters($request->query->all()));
            $ids = $this->studyQueueBuilder->build($candidates, LearningCardQuery::STUDY_LIMIT);
            $session->set(self::STUDY_IDS_KEY, $ids);
            $session->set(self::STUDY_INDEX_KEY, 0);
            $session->set(self::STUDY_REVEALED_KEY, false);
            if ($ids !== []) {
                $session->set(self::STUDY_SESSION_ID_KEY, $this->newStudySessionId());
                $session->set(self::STUDY_STARTED_AT_KEY, $this->formatTime($this->clock->now()));
            } else {
                $session->remove(self::STUDY_SESSION_ID_KEY);
                $session->remove(self::STUDY_STARTED_AT_KEY);
            }
        }

        $ids = $this->sessionIds($session);
        $index = max(0, (int) $session->get(self::STUDY_INDEX_KEY, 0));
        $cards = $this->learningCardRepository->findActiveByIds($ids);
        $total = count($cards);
        $card = $index < $total ? $cards[$index] : null;
        if ($card !== null && !$session->has(self::STUDY_STARTED_AT_KEY)) {
            $session->set(self::STUDY_STARTED_AT_KEY, $this->formatTime($this->clock->now()));
        }
        $studySessionId = $this->studySessionId($session);

        return $this->render('learning/study.html.twig', [
            'card' => $card,
            'revealed' => (bool) $session->get(self::STUDY_REVEALED_KEY, false),
            'index' => min($index, $total),
            'total' => $total,
            'selectedCount' => count($ids),
            'studySessionId' => $studySessionId,
            'ratings' => ReviewRating::cases(),
            'summary' => $studySessionId !== null ? $this->learningReviewRepository->sessionSummary($studySessionId) : null,
            'types' => LearningCardType::cases(),
            'statuses' => VocabularyStatus::cases(),
            'publications' => $this->publicationRepository->findAllOrderedByCreatedAt(),
        ]);
    }

    #[Route('/learning/study/reveal', name: 'learning_study_reveal', methods: ['POST'])]
    public function reveal(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('learning_study', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $request->getSession()->set(self::STUDY_REVEALED_KEY, true);

        return $this->redirectToRoute('learning_study', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/learning/study/review', name: 'learning_study_review', methods: ['POST'])]
    public function review(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('learning_study', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $session = $request->getSession();
        $studySessionId = $this->studySessionId($session);
        $position = max(0, (int) $session->get(self::STUDY_INDEX_KEY, 0));
        $postedPosition = filter_var($request->request->get('studyPosition'), FILTER_VALIDATE_INT);
        $postedCardId = filter_var($request->request->get('cardId'), FILTER_VALIDATE_INT);
        $postedStudySessionId = (string) $request->request->get('studySessionId', '');
        $rating = ReviewRating::tryFrom((string) $request->request->get('rating', ''));
        $startedAt = $this->startedAt($session);
        $card = $this->currentStudyCard($session);

        if (
            $studySessionId === null
            || $postedStudySessionId !== $studySessionId
            || $postedPosition !== $position
            || $card === null
            || $postedCardId !== $card->getId()
            || $rating === null
            || !(bool) $session->get(self::STUDY_REVEALED_KEY, false)
            || $startedAt === null
        ) {
            $this->addFlash('error', 'Cannot record review for the current card.');

            return $this->redirectToRoute('learning_study', [], Response::HTTP_SEE_OTHER);
        }

        $this->recordLearningReviewHandler->record(
            card: $card,
            rating: $rating,
            studySessionId: $studySessionId,
            studyPosition: $position,
            startedAt: $startedAt,
        );

        $nextPosition = $position + 1;
        $session->set(self::STUDY_INDEX_KEY, $nextPosition);
        $session->set(self::STUDY_REVEALED_KEY, false);
        if ($nextPosition < count($this->sessionIds($session))) {
            $session->set(self::STUDY_STARTED_AT_KEY, $this->formatTime($this->clock->now()));
        } else {
            $session->remove(self::STUDY_STARTED_AT_KEY);
        }

        return $this->redirectToRoute('learning_study', [], Response::HTTP_SEE_OTHER);
    }

    private function shouldStartStudy(Request $request, SessionInterface $session): bool
    {
        return !$session->has(self::STUDY_IDS_KEY)
            || $request->query->has('start')
            || $request->query->has('publication')
            || $request->query->has('type')
            || $request->query->has('status');
    }

    /**
     * @return list<int>
     */
    private function sessionIds(SessionInterface $session): array
    {
        $ids = $session->get(self::STUDY_IDS_KEY, []);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }

    private function currentStudyCard(SessionInterface $session): ?LearningCard
    {
        $index = max(0, (int) $session->get(self::STUDY_INDEX_KEY, 0));
        $ids = $this->sessionIds($session);
        if (!isset($ids[$index])) {
            return null;
        }

        return $this->learningCardRepository->findActiveByIds([$ids[$index]])[0] ?? null;
    }

    private function studySessionId(SessionInterface $session): ?string
    {
        $studySessionId = $session->get(self::STUDY_SESSION_ID_KEY);

        return is_string($studySessionId) && $studySessionId !== '' ? $studySessionId : null;
    }

    private function startedAt(SessionInterface $session): ?\DateTimeImmutable
    {
        $value = $session->get(self::STUDY_STARTED_AT_KEY);
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function formatTime(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private function newStudySessionId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    private function cardCsrfTokenId(LearningCard $card): string
    {
        return 'learning_card_'.$card->getId();
    }

    /**
     * @return array<string, string|int>
     */
    private function redirectParameters(Request $request): array
    {
        return LearningCardQuery::fromParameters([
            'q' => $request->request->get('currentQuery', ''),
            'type' => $request->request->get('currentType', 'all'),
            'publication' => $request->request->get('currentPublication', 'all'),
            'status' => $request->request->get('currentStatus', 'all'),
            'active' => $request->request->get('currentActive', 'yes'),
            'sort' => $request->request->get('currentSort', LearningCardQuery::DEFAULT_SORT),
            'direction' => $request->request->get('currentDirection', LearningCardQuery::DEFAULT_DIRECTION),
            'page' => $request->request->get('currentPage', LearningCardQuery::DEFAULT_PAGE),
            'perPage' => $request->request->get('currentPerPage', LearningCardQuery::DEFAULT_PER_PAGE),
        ])->toUrlParameters();
    }

    /**
     * @return array<string, array{params: array<string, string|int>}>
     */
    private function sortLinks(LearningCardQuery $query): array
    {
        $links = [];
        foreach ([LearningCardQuery::SORT_LEMMA, LearningCardQuery::SORT_TYPE, LearningCardQuery::SORT_CREATED_AT, LearningCardQuery::SORT_PUBLICATION] as $sort) {
            $direction = $query->sort === $sort
                ? ($query->direction === LearningCardQuery::DIRECTION_ASC ? LearningCardQuery::DIRECTION_DESC : LearningCardQuery::DIRECTION_ASC)
                : LearningCardQuery::defaultDirectionForSort($sort);

            $links[$sort] = [
                'params' => (new LearningCardQuery(
                    search: $query->search,
                    type: $query->type,
                    publicationId: $query->publicationId,
                    status: $query->status,
                    active: $query->active,
                    sort: $sort,
                    direction: $direction,
                    page: 1,
                    perPage: $query->perPage,
                ))->toUrlParameters(),
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
}
