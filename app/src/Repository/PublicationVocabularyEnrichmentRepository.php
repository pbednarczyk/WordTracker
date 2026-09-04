<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationVocabularyEnrichment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationVocabularyEnrichment>
 */
final class PublicationVocabularyEnrichmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationVocabularyEnrichment::class);
    }
}
