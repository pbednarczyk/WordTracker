<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VocabularyItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VocabularyItem>
 */
final class VocabularyItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VocabularyItem::class);
    }

    public function findOneByIdentity(string $language, string $lemma, string $partOfSpeech): ?VocabularyItem
    {
        return $this->findOneBy([
            'language' => $language,
            'lemma' => $lemma,
            'partOfSpeech' => $partOfSpeech,
        ]);
    }
}
