<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningCard;
use App\Entity\PublicationVocabulary;
use App\Enum\LearningCardType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearningCard>
 */
final class LearningCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearningCard::class);
    }

    /**
     * @return list<LearningCardType>
     */
    public function existingTypesForPublicationVocabulary(PublicationVocabulary $publicationVocabulary): array
    {
        $rows = $this->createQueryBuilder('lc')
            ->select('lc.type AS type')
            ->andWhere('lc.publicationVocabulary = :publicationVocabulary')
            ->setParameter('publicationVocabulary', $publicationVocabulary)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_map(
            static fn (array $row): ?LearningCardType => $row['type'] instanceof LearningCardType
                ? $row['type']
                : LearningCardType::tryFrom((string) $row['type']),
            $rows,
        )));
    }

    /**
     * @param list<PublicationVocabulary> $publicationVocabularyRows
     *
     * @return array<int, int>
     */
    public function countByPublicationVocabulary(array $publicationVocabularyRows): array
    {
        if ($publicationVocabularyRows === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('lc')
            ->select('IDENTITY(lc.publicationVocabulary) AS publicationVocabularyId')
            ->addSelect('COUNT(lc.id) AS cardCount')
            ->andWhere('lc.publicationVocabulary IN (:rows)')
            ->groupBy('lc.publicationVocabulary')
            ->setParameter('rows', $publicationVocabularyRows)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['publicationVocabularyId']] = (int) $row['cardCount'];
        }

        return $counts;
    }

    public function findPaginated(LearningCardQuery $query): PaginatedLearningCardResult
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

        return new PaginatedLearningCardResult($items, $totalItems, $page, $query->perPage, $totalPages);
    }

    /**
     * @return list<int>
     */
    public function findStudyIds(LearningCardQuery $query, int $limit = LearningCardQuery::STUDY_LIMIT): array
    {
        $query = new LearningCardQuery(
            search: $query->search,
            type: $query->type,
            publicationId: $query->publicationId,
            status: $query->status,
            active: LearningCardQuery::ACTIVE_YES,
            sort: LearningCardQuery::SORT_CREATED_AT,
            direction: LearningCardQuery::DIRECTION_ASC,
            page: 1,
            perPage: $limit,
        );

        return array_map('intval', $this->baseQueryBuilder($query)
            ->select('lc.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * @param list<int> $ids
     *
     * @return list<LearningCard>
     */
    public function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $cards = $this->createQueryBuilder('lc')
            ->addSelect('vi', 'pv', 'p', 'e')
            ->innerJoin('lc.vocabularyItem', 'vi')
            ->leftJoin('lc.publicationVocabulary', 'pv')
            ->leftJoin('pv.publication', 'p')
            ->leftJoin('lc.publicationVocabularyEnrichment', 'e')
            ->andWhere('lc.id IN (:ids)')
            ->andWhere('lc.isActive = true')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->getId()] = $card;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?LearningCard => $byId[$id] ?? null, $ids)));
    }

    private function countForQuery(LearningCardQuery $query): int
    {
        $queryBuilder = $this->createQueryBuilder('lc')
            ->select('COUNT(DISTINCT lc.id)')
            ->innerJoin('lc.vocabularyItem', 'vi')
            ->leftJoin('lc.publicationVocabulary', 'pv')
            ->leftJoin('pv.publication', 'p');

        $this->applyFilters($queryBuilder, $query);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    private function baseQueryBuilder(LearningCardQuery $query): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('lc')
            ->addSelect('vi', 'pv', 'p', 'e')
            ->innerJoin('lc.vocabularyItem', 'vi')
            ->leftJoin('lc.publicationVocabulary', 'pv')
            ->leftJoin('pv.publication', 'p')
            ->leftJoin('lc.publicationVocabularyEnrichment', 'e');

        $this->applyFilters($queryBuilder, $query);
        $this->applySorting($queryBuilder, $query);

        return $queryBuilder;
    }

    private function applyFilters(QueryBuilder $queryBuilder, LearningCardQuery $query): void
    {
        if ($query->search !== '') {
            $queryBuilder
                ->andWhere('LOWER(vi.lemma) LIKE :lemma')
                ->setParameter('lemma', '%'.mb_strtolower($query->search).'%');
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

        if ($query->status !== null) {
            $queryBuilder
                ->andWhere('vi.status = :status')
                ->setParameter('status', $query->status);
        }

        if ($query->active === LearningCardQuery::ACTIVE_YES) {
            $queryBuilder->andWhere('lc.isActive = true');
        } elseif ($query->active === LearningCardQuery::ACTIVE_NO) {
            $queryBuilder->andWhere('lc.isActive = false');
        }
    }

    private function applySorting(QueryBuilder $queryBuilder, LearningCardQuery $query): void
    {
        $sortExpression = match ($query->sort) {
            LearningCardQuery::SORT_LEMMA => 'vi.lemma',
            LearningCardQuery::SORT_TYPE => 'lc.type',
            LearningCardQuery::SORT_PUBLICATION => 'p.title',
            default => 'lc.createdAt',
        };

        $queryBuilder
            ->orderBy($sortExpression, strtoupper($query->direction))
            ->addOrderBy('vi.lemma', 'ASC')
            ->addOrderBy('lc.id', 'ASC');
    }
}
