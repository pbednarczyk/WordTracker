<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Enum\VocabularyStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /**
     * @return list<PublicationVocabulary>
     */
    public function findForPublicationFiltered(
        Publication $publication,
        ?VocabularyStatus $status = null,
        ?string $query = null,
    ): array {
        $queryBuilder = $this->createQueryBuilder('pv')
            ->addSelect('vi')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->andWhere('pv.publication = :publication')
            ->setParameter('publication', $publication);

        if ($status !== null) {
            $queryBuilder
                ->andWhere('vi.status = :status')
                ->setParameter('status', $status);
        }

        $normalizedQuery = trim((string) $query);
        if ($normalizedQuery !== '') {
            $queryBuilder
                ->andWhere('LOWER(vi.lemma) LIKE :lemma')
                ->setParameter('lemma', '%'.mb_strtolower($normalizedQuery).'%');
        }

        return $queryBuilder
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

        return [
            'uniqueTotal' => (int) $row['uniqueTotal'],
            'uniqueKnown' => (int) $row['uniqueKnown'],
            'uniqueUnknown' => (int) $row['uniqueUnknown'],
            'occurrencesTotal' => (int) $row['occurrencesTotal'],
            'occurrencesKnown' => (int) $row['occurrencesKnown'],
            'occurrencesUnknown' => (int) $row['occurrencesUnknown'],
        ];
    }
}
