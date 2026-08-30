<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\AnalyzePublicationHandler;
use App\Application\PublicationAnalysisException;
use App\Entity\Publication;
use App\Enum\PublicationType;
use App\Nlp\TextAnalyzerException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wordtracker:fixture:analyze',
    description: 'Create and analyze a development publication from fixtures/sample.txt.',
)]
final class AnalyzeFixtureCommand extends Command
{
    public function __construct(
        private readonly AnalyzePublicationHandler $analyzePublication,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $fixturesDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fixturePath = rtrim($this->fixturesDir, '/\\').DIRECTORY_SEPARATOR.'sample.txt';

        if (!is_file($fixturePath)) {
            $io->error(sprintf('Fixture file was not found: %s', $fixturePath));

            return Command::FAILURE;
        }

        $rawText = file_get_contents($fixturePath);
        if ($rawText === false || trim($rawText) === '') {
            $io->error(sprintf('Fixture file is empty or unreadable: %s', $fixturePath));

            return Command::FAILURE;
        }

        $publication = new Publication(
            title: 'Sample',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: $rawText,
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        try {
            $result = ($this->analyzePublication)($publication);
        } catch (PublicationAnalysisException|TextAnalyzerException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Fixture publication analyzed successfully. Publication ID: %d', $publication->getId()));
        $io->table(['Metric', 'Value'], [
            ['Tokens', (string) $result->tokenCount],
            ['Words', (string) $result->wordCount],
            ['Vocabulary occurrences', (string) $result->vocabularyOccurrences],
            ['Unique vocabulary items', (string) $result->uniqueVocabularyItems],
            ['Ignored proper nouns', (string) $result->ignoredProperNouns],
        ]);

        return Command::SUCCESS;
    }
}
