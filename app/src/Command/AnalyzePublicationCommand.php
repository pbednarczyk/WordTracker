<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\AnalyzePublicationHandler;
use App\Application\PublicationAnalysisException;
use App\Nlp\TextAnalyzerException;
use App\Repository\PublicationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'wordtracker:publication:analyze',
    description: 'Analyze an existing publication rawText through the NLP service.',
)]
final class AnalyzePublicationCommand extends Command
{
    public function __construct(
        private readonly PublicationRepository $publicationRepository,
        private readonly AnalyzePublicationHandler $analyzePublication,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('publication-id', InputArgument::REQUIRED, 'Publication ID to analyze.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicationId = (int) $input->getArgument('publication-id');
        $publication = $this->publicationRepository->find($publicationId);

        if ($publication === null) {
            $io->error(sprintf('Publication %d was not found.', $publicationId));

            return Command::FAILURE;
        }

        try {
            $result = ($this->analyzePublication)($publication);
        } catch (PublicationAnalysisException|TextAnalyzerException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Publication analyzed successfully.');
        $io->table(['Metric', 'Value'], [
            ['Tokens', (string) $result->tokenCount],
            ['Words', (string) $result->wordCount],
            ['Vocabulary occurrences', (string) $result->vocabularyOccurrences],
            ['Unique vocabulary items', (string) $result->uniqueVocabularyItems],
            ['Ignored named entities', (string) $result->ignoredNamedEntities],
        ]);

        return Command::SUCCESS;
    }
}
