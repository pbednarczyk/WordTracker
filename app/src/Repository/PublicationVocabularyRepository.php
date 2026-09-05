<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\VocabularyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationVocabulary>
 */
final class PublicationVocabularyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationVocabulary::class);
    }

    /**
     * @return list<PublicationVocabulary>
     */
    public function findForPublicationOrdered(Publication $publication): array
    {
        return $this->findForPublicationFiltered($publication);
    }

    public function findForPublication(Publication $publication, PublicationVocabularyQuery $query): PaginatedPublicationVocabularyResult
    {
        $totalItems = $this->countForPublication($publication, $query);
        $totalPages = max(1, (int) ceil($totalItems / $query->perPage));
        $page = min($query->page, $totalPages);

        $items = [];
        if ($totalItems > 0) {
            $items = $this->baseFilteredQueryBuilder($publication, $query)
                ->setFirstResult(($page - 1) * $query->perPage)
                ->setMaxResults($query->perPage)
                ->getQuery()
                ->getResult();
        }

        return new PaginatedPublicationVocabularyResult(
            items: $items,
            totalItems: $totalItems,
            page: $page,
            perPage: $query->perPage,
            totalPages: $totalPages,
        );
    }

    /**
     * @return list<PublicationVocabulary>
     */
    public function findForPublicationFiltered(
        Publication $publication,
        ?VocabularyStatus $status = null,
        ?string $query = null,
    ): array {
        return $this->findAllForPublication($publication, new PublicationVocabularyQuery(
            search: trim((string) $query),
            status: $status,
        ));
    }

    /**
     * @return list<PublicationVocabulary>
     */
    public function findAllForPublication(Publication $publication, PublicationVocabularyQuery $query): array
    {
        return $this->baseFilteredQueryBuilder($publication, $query)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PublicationVocabulary>
     */
    public function findForVocabularyItemWithEnrichment(VocabularyItem $item): array
    {
        return $this->createQueryBuilder('pv')
            ->addSelect('p', 'e')
            ->innerJoin('pv.publication', 'p')
            ->leftJoin('pv.enrichment', 'e')
            ->andWhere('pv.vocabularyItem = :item')
            ->setParameter('item', $item)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $itemIds
     *
     * @return list<PublicationVocabulary>
     */
    public function findForPublicationAndVocabularyItemIds(Publication $publication, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, static fn (int $id): bool => $id > 0)));
        if ($itemIds === []) {
            return [];
        }

        return $this->createQueryBuilder('pv')
            ->addSelect('vi', 'e')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->leftJoin('pv.enrichment', 'e')
            ->andWhere('pv.publication = :publication')
            ->andWhere('vi.id IN (:itemIds)')
            ->setParameter('publication', $publication)
            ->setParameter('itemIds', $itemIds)
            ->orderBy('pv.occurrences', 'DESC')
            ->addOrderBy('vi.lemma', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{
     *     uniqueTotal: int,
     *     uniqueKnown: int,
     *     uniqueUnknown: int,
     *     occurrencesTotal: int,
     *     occurrencesKnown: int,
     *     occurrencesUnknown: int
     * }
     */
    public function getCoverageStats(Publication $publication): array
    {
        $row = $this->createQueryBuilder('pv')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->select('COUNT(pv.id) AS uniqueTotal')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :known THEN 1 ELSE 0 END), 0) AS uniqueKnown')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :unknown THEN 1 ELSE 0 END), 0) AS uniqueUnknown')
            ->addSelect('COALESCE(SUM(pv.occurrences), 0) AS occurrencesTotal')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :known THEN pv.occurrences ELSE 0 END), 0) AS occurrencesKnown')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :unknown THEN pv.occurrences ELSE 0 END), 0) AS occurrencesUnknown')
            ->andWhere('pv.publication = :publication')
            ->setParameter('publication', $publication)
            ->setParameter('known', VocabularyStatus::KNOWN)
            ->setParameter('unknown', VocabularyStatus::UNKNOWN)
            ->getQuery()
            ->getSingleResult();

        return $this->normalizeCoverageStats($row);
    }

    /**
     * @param list<Publication> $publications
     *
     * @return array<int, array{
     *     uniqueTotal: int,
     *     uniqueKnown: int,
     *     uniqueUnknown: int,
     *     occurrencesTotal: int,
     *     occurrencesKnown: int,
     *     occurrencesUnknown: int
     * }>
     */
    public function getCoverageStatsForPublications(array $publications): array
    {
        if ($publications === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('pv')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->select('IDENTITY(pv.publication) AS publicationId')
            ->addSelect('COUNT(pv.id) AS uniqueTotal')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :known THEN 1 ELSE 0 END), 0) AS uniqueKnown')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :unknown THEN 1 ELSE 0 END), 0) AS uniqueUnknown')
            ->addSelect('COALESCE(SUM(pv.occurrences), 0) AS occurrencesTotal')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :known THEN pv.occurrences ELSE 0 END), 0) AS occurrencesKnown')
            ->addSelect('COALESCE(SUM(CASE WHEN vi.status = :unknown THEN pv.occurrences ELSE 0 END), 0) AS occurrencesUnknown')
            ->andWhere('pv.publication IN (:publications)')
            ->groupBy('pv.publication')
            ->setParameter('publications', $publications)
            ->setParameter('known', VocabularyStatus::KNOWN)
            ->setParameter('unknown', VocabularyStatus::UNKNOWN)
            ->getQuery()
            ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['publicationId']] = $this->normalizeCoverageStats($row);
        }

        return $stats;
    }

    /**
     * @param array{
     *     uniqueTotal: mixed,
     *     uniqueKnown: mixed,
     *     uniqueUnknown: mixed,
     *     occurrencesTotal: mixed,
     *     occurrencesKnown: mixed,
     *     occurrencesUnknown: mixed
     * } $row
     *
     * @return array{
     *     uniqueTotal: int,
     *     uniqueKnown: int,
     *     uniqueUnknown: int,
     *     occurrencesTotal: int,
     *     occurrencesKnown: int,
     *     occurrencesUnknown: int
     * }
     */
    private function normalizeCoverageStats(array $row): array
    {
        return [
            'uniqueTotal' => (int) $row['uniqueTotal'],
            'uniqueKnown' => (int) $row['uniqueKnown'],
            'uniqueUnknown' => (int) $row['uniqueUnknown'],
            'occurrencesTotal' => (int) $row['occurrencesTotal'],
            'occurrencesKnown' => (int) $row['occurrencesKnown'],
            'occurrencesUnknown' => (int) $row['occurrencesUnknown'],
        ];
    }

    private function countForPublication(Publication $publication, PublicationVocabularyQuery $query): int
    {
        $queryBuilder = $this->createQueryBuilder('pv')
            ->select('COUNT(DISTINCT pv.id)')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->andWhere('pv.publication = :publication')
            ->setParameter('publication', $publication);

        if ($query->enriched !== PublicationVocabularyQuery::ENRICHED_ALL) {
            $queryBuilder->leftJoin('pv.enrichment', 'e');
        }

        $this->applyFilters($queryBuilder, $query);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    private function baseFilteredQueryBuilder(Publication $publication, PublicationVocabularyQuery $query): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('pv')
            ->addSelect('vi', 'e')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->leftJoin('pv.enrichment', 'e')
            ->andWhere('pv.publication = :publication')
            ->setParameter('publication', $publication);

        $this->applyFilters($queryBuilder, $query);
        $this->applySorting($queryBuilder, $query);

        return $queryBuilder;
    }

    private function applyFilters(QueryBuilder $queryBuilder, PublicationVocabularyQuery $query): void
    {
        if ($query->status !== null) {
            $queryBuilder
                ->andWhere('vi.status = :status')
                ->setParameter('status', $query->status);
        }

        if ($query->search !== '') {
            $queryBuilder
                ->andWhere('LOWER(vi.lemma) LIKE :lemma')
                ->setParameter('lemma', '%'.mb_strtolower($query->search).'%');
        }

        if ($query->enriched === PublicationVocabularyQuery::ENRICHED_YES) {
            $queryBuilder->andWhere('e.id IS NOT NULL');
        } elseif ($query->enriched === PublicationVocabularyQuery::ENRICHED_NO) {
            $queryBuilder->andWhere('e.id IS NULL');
        }

        if ($query->partOfSpeech !== null) {
            $queryBuilder
                ->andWhere('vi.partOfSpeech = :partOfSpeech')
                ->setParameter('partOfSpeech', $query->partOfSpeech);
        }
    }

    private function applySorting(QueryBuilder $queryBuilder, PublicationVocabularyQuery $query): void
    {
        $direction = strtoupper($query->direction);
        $sortExpression = match ($query->sort) {
            PublicationVocabularyQuery::SORT_LEMMA => 'vi.lemma',
            PublicationVocabularyQuery::SORT_POS => 'vi.partOfSpeech',
            PublicationVocabularyQuery::SORT_STATUS => 'vi.status',
            PublicationVocabularyQuery::SORT_ENRICHED => 'enrichmentState',
            default => 'pv.occurrences',
        };

        if ($query->sort === PublicationVocabularyQuery::SORT_ENRICHED) {
            $queryBuilder->addSelect('CASE WHEN e.id IS NULL THEN 0 ELSE 1 END AS HIDDEN enrichmentState');
        }

        $queryBuilder->orderBy($sortExpression, $direction);

        if ($query->sort !== PublicationVocabularyQuery::SORT_LEMMA) {
            $queryBuilder->addOrderBy('vi.lemma', 'ASC');
        }

        $queryBuilder->addOrderBy('pv.id', 'ASC');
    }
}
