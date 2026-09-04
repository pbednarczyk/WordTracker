<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VocabularyOccurrence;
use App\Entity\Publication;
use App\Entity\VocabularyItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VocabularyOccurrence>
 */
final class VocabularyOccurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VocabularyOccurrence::class);
    }

    /**
     * @return list<VocabularyOccurrence>
     */
    public function findForVocabularyItem(VocabularyItem $item): array
    {
        return $this->createQueryBuilder('vo')
            ->addSelect('p')
            ->innerJoin('vo.publication', 'p')
            ->andWhere('vo.vocabularyItem = :item')
            ->setParameter('item', $item)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('vo.position', 'ASC')
            ->addOrderBy('vo.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{totalOccurrences: int, publicationCount: int}
     */
    public function getSummaryForVocabularyItem(VocabularyItem $item): array
    {
        $row = $this->createQueryBuilder('vo')
            ->select('COUNT(vo.id) AS totalOccurrences')
            ->addSelect('COUNT(DISTINCT p.id) AS publicationCount')
            ->innerJoin('vo.publication', 'p')
            ->andWhere('vo.vocabularyItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleResult();

        return [
            'totalOccurrences' => (int) $row['totalOccurrences'],
            'publicationCount' => (int) $row['publicationCount'],
        ];
    }

    /**
     * @param list<VocabularyItem> $items
     *
     * @return array<int, string>
     */
    public function findFirstSentencesForPublicationItems(Publication $publication, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('vo')
            ->select('IDENTITY(vo.vocabularyItem) AS itemId')
            ->addSelect('vo.sentence AS sentence')
            ->andWhere('vo.publication = :publication')
            ->andWhere('vo.vocabularyItem IN (:items)')
            ->setParameter('publication', $publication)
            ->setParameter('items', $items)
            ->orderBy('vo.position', 'ASC')
            ->addOrderBy('vo.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $sentences = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['itemId'];
            if (!array_key_exists($itemId, $sentences)) {
                $sentences[$itemId] = (string) ($row['sentence'] ?? '');
            }
        }

        return $sentences;
    }
}
