<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Publication;
use App\Enum\VocabularyStatus;
use App\Repository\PublicationVocabularyRepository;
use App\Repository\VocabularyOccurrenceRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class PublicationVocabularyExporter
{
    /**
     * @var list<string>
     */
    public const CSV_HEADERS = [
        'lemma',
        'part_of_speech',
        'status',
        'occurrences',
        'language',
        'first_context_sentence',
    ];

    public function __construct(
        private PublicationVocabularyRepository $publicationVocabularyRepository,
        private VocabularyOccurrenceRepository $vocabularyOccurrenceRepository,
    ) {
    }

    /**
     * @return list<VocabularyExportRow>
     */
    public function rows(Publication $publication, ?VocabularyStatus $status = null, ?string $query = null): array
    {
        $publicationVocabulary = $this->publicationVocabularyRepository->findForPublicationFiltered(
            publication: $publication,
            status: $status,
            query: $query,
        );
        $items = array_map(static fn ($row) => $row->getVocabularyItem(), $publicationVocabulary);
        $contexts = $this->vocabularyOccurrenceRepository->findFirstSentencesForPublicationItems($publication, $items);

        $rows = [];
        foreach ($publicationVocabulary as $row) {
            $item = $row->getVocabularyItem();
            $itemId = $item->getId();
            $rows[] = new VocabularyExportRow(
                lemma: $item->getLemma(),
                partOfSpeech: $item->getPartOfSpeech(),
                status: $item->getStatus()->value,
                occurrences: $row->getOccurrences(),
                language: $item->getLanguage(),
                firstContextSentence: $itemId === null ? '' : ($contexts[$itemId] ?? ''),
            );
        }

        return $rows;
    }

    public function csv(Publication $publication, ?VocabularyStatus $status = null, ?string $query = null): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open CSV stream.');
        }

        fputcsv($stream, self::CSV_HEADERS, ',', '"', '');
        foreach ($this->rows($publication, $status, $query) as $row) {
            fputcsv($stream, [
                $row->lemma,
                $row->partOfSpeech,
                $row->status,
                $row->occurrences,
                $row->language,
                $row->firstContextSentence,
            ], ',', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Unable to read CSV stream.');
        }

        return $csv;
    }

    public function xlsx(Publication $publication, ?VocabularyStatus $status = null, ?string $query = null): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(self::CSV_HEADERS, null, 'A1');

        $rowNumber = 2;
        foreach ($this->rows($publication, $status, $query) as $row) {
            $sheet->fromArray([
                $row->lemma,
                $row->partOfSpeech,
                $row->status,
                $row->occurrences,
                $row->language,
                $row->firstContextSentence,
            ], null, 'A'.$rowNumber);
            ++$rowNumber;
        }

        $path = tempnam(sys_get_temp_dir(), 'wordtracker-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create XLSX file.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);
            $contents = file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }

        if ($contents === false) {
            throw new \RuntimeException('Unable to read XLSX file.');
        }

        return $contents;
    }

    public function filename(Publication $publication, string $extension): string
    {
        $slug = (new AsciiSlugger())->slug($publication->getTitle())->lower()->toString();
        if ($slug === '') {
            $slug = 'publication-'.$publication->getId();
        }

        return sprintf('%s-vocabulary.%s', $slug, $extension);
    }
}
