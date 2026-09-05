<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LearningCard;
use App\Enum\LearningCardType;
use App\Enum\VocabularyStatus;
use App\Repository\LearningCardQuery;
use App\Repository\LearningCardRepository;
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

    public function __construct(
        private readonly LearningCardRepository $learningCardRepository,
        private readonly PublicationRepository $publicationRepository,
        private readonly EntityManagerInterface $entityManager,
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
            $ids = $this->learningCardRepository->findStudyIds(LearningCardQuery::fromParameters($request->query->all()));
            $session->set(self::STUDY_IDS_KEY, $ids);
            $session->set(self::STUDY_INDEX_KEY, 0);
            $session->set(self::STUDY_REVEALED_KEY, false);
        }

        $ids = $this->sessionIds($session);
        $index = max(0, (int) $session->get(self::STUDY_INDEX_KEY, 0));
        $cards = $this->learningCardRepository->findActiveByIds($ids);
        $total = count($cards);
        $card = $index < $total ? $cards[$index] : null;

        return $this->render('learning/study.html.twig', [
            'card' => $card,
            'revealed' => (bool) $session->get(self::STUDY_REVEALED_KEY, false),
            'index' => min($index, $total),
            'total' => $total,
            'selectedCount' => count($ids),
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

    #[Route('/learning/study/next', name: 'learning_study_next', methods: ['POST'])]
    public function next(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('learning_study', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $session = $request->getSession();
        $session->set(self::STUDY_INDEX_KEY, ((int) $session->get(self::STUDY_INDEX_KEY, 0)) + 1);
        $session->set(self::STUDY_REVEALED_KEY, false);

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
