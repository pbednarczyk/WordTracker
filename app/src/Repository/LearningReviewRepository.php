<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Enum\ReviewRating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningReview>
 */
final class LearningReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningReview::class);
    }

    public function findOneByPresentation(string $studySessionId, int $studyPosition): ?LearningReview
    {
        return $this->findOneBy([
            'studySessionId' => $studySessionId,
            'studyPosition' => $studyPosition,
        ]);
    }

    public function findPaginated(LearningReviewQuery $query): PaginatedLearningReviewResult
    {
        $totalItems = $this->countForQuery($query);
        $totalPages = max(1, (int) ceil($totalItems / $query->perPage));
        $page = min($query->page, $totalPages);
        $items = [];

        if ($totalItems > 0) {
            $items = $this->baseQueryBuilder($query)
                ->setFirstResult(($page - 1) * $query->perPage)
                ->setMaxResults($query->perPage)
                ->getQuery()
                ->getResult();
        }

        return new PaginatedLearningReviewResult($items, $totalItems, $page, $query->perPage, $totalPages);
    }

    /**
     * @return array{total: int, AGAIN: int, HARD: int, GOOD: int, EASY: int}
     */
    public function sessionSummary(string $studySessionId): array
    {
        $rows = $this->createQueryBuilder('lr')
            ->select('lr.rating AS rating')
            ->addSelect('COUNT(lr.id) AS ratingCount')
            ->andWhere('lr.studySessionId = :studySessionId')
            ->groupBy('lr.rating')
            ->setParameter('studySessionId', $studySessionId)
            ->getQuery()
            ->getArrayResult();

        $summary = ['total' => 0];
        foreach (ReviewRating::cases() as $rating) {
            $summary[$rating->value] = 0;
        }

        foreach ($rows as $row) {
            $rating = $row['rating'] instanceof ReviewRating ? $row['rating']->value : (string) $row['rating'];
            $count = (int) $row['ratingCount'];
            if (array_key_exists($rating, $summary)) {
                $summary[$rating] = $count;
                $summary['total'] += $count;
            }
        }

        return $summary;
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return array<int, array{reviewCount: int, lastReviewedAt: ?\DateTimeImmutable}>
     */
    public function statsByLearningCards(array $cards): array
    {
        if ($cards === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('lr')
            ->select('IDENTITY(lr.learningCard) AS cardId')
            ->addSelect('COUNT(lr.id) AS reviewCount')
            ->addSelect('MAX(lr.reviewedAt) AS lastReviewedAt')
            ->andWhere('lr.learningCard IN (:cards)')
            ->groupBy('lr.learningCard')
            ->setParameter('cards', $cards)
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $lastReviewedAt = $this->dateTimeOrNull($row['lastReviewedAt']);
            $stats[(int) $row['cardId']] = [
                'reviewCount' => (int) $row['reviewCount'],
                'lastReviewedAt' => $lastReviewedAt,
            ];
        }

        return $stats;
    }

    public function countForCard(LearningCard $card): int
    {
        return (int) $this->createQueryBuilder('lr')
            ->select('COUNT(lr.id)')
            ->andWhere('lr.learningCard = :card')
            ->setParameter('card', $card)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function lastForCard(LearningCard $card): ?LearningReview
    {
        return $this->createQueryBuilder('lr')
            ->andWhere('lr.learningCard = :card')
            ->orderBy('lr.reviewedAt', 'DESC')
            ->addOrderBy('lr.id', 'DESC')
            ->setParameter('card', $card)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function countForQuery(LearningReviewQuery $query): int
    {
        $queryBuilder = $this->createQueryBuilder('lr')
            ->select('COUNT(DISTINCT lr.id)')
            ->leftJoin('lr.learningCard', 'lc')
            ->leftJoin('lc.vocabularyItem', 'vi')
            ->leftJoin('lc.publicationVocabulary', 'pv')
            ->leftJoin('pv.publication', 'p');

        $this->applyFilters($queryBuilder, $query);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    private function baseQueryBuilder(LearningReviewQuery $query): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('lr')
            ->addSelect('lc', 'vi', 'pv', 'p')
            ->leftJoin('lr.learningCard', 'lc')
            ->leftJoin('lc.vocabularyItem', 'vi')
            ->leftJoin('lc.publicationVocabulary', 'pv')
            ->leftJoin('pv.publication', 'p');

        $this->applyFilters($queryBuilder, $query);

        return $queryBuilder
            ->orderBy('lr.reviewedAt', 'DESC')
            ->addOrderBy('lr.id', 'DESC');
    }

    private function applyFilters(QueryBuilder $queryBuilder, LearningReviewQuery $query): void
    {
        if ($query->search !== '') {
            $queryBuilder
                ->andWhere('LOWER(vi.lemma) LIKE :lemma')
                ->setParameter('lemma', '%'.mb_strtolower($query->search).'%');
        }

        if ($query->rating !== null) {
            $queryBuilder
                ->andWhere('lr.rating = :rating')
                ->setParameter('rating', $query->rating);
        }

        if ($query->type !== null) {
            $queryBuilder
                ->andWhere('lc.type = :type')
                ->setParameter('type', $query->type);
        }

        if ($query->publicationId !== null) {
            $queryBuilder
                ->andWhere('p.id = :publicationId')
                ->setParameter('publicationId', $query->publicationId);
        }
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
