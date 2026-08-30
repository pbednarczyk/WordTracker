<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
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
        return $this->createQueryBuilder('pv')
            ->addSelect('vi')
            ->innerJoin('pv.vocabularyItem', 'vi')
            ->andWhere('pv.publication = :publication')
            ->setParameter('publication', $publication)
            ->orderBy('pv.occurrences', 'DESC')
            ->addOrderBy('vi.lemma', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
