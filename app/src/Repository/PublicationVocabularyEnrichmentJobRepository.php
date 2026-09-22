<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichmentJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PublicationVocabularyEnrichmentJob> */
final class PublicationVocabularyEnrichmentJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationVocabularyEnrichmentJob::class);
    }

    public function findActiveUuid(string $uuid): ?PublicationVocabularyEnrichmentJob
    {
        return $this->findOneBy(['activeLlmJobId' => $uuid]);
    }

    public function latest(PublicationVocabulary $context): ?PublicationVocabularyEnrichmentJob
    {
        return $this->findOneBy(['publicationVocabulary' => $context], ['id' => 'DESC']);
    }
}
