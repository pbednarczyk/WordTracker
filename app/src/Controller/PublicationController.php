<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AnalyzePublicationHandler;
use App\Application\PublicationAnalysisException;
use App\Entity\Publication;
use App\Form\Model\PublicationInput;
use App\Form\PublicationFormType;
use App\Nlp\TextAnalyzerException;
use App\Repository\PublicationRepository;
use App\Repository\PublicationVocabularyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractController
{
    public function __construct(
        private readonly PublicationRepository $publicationRepository,
        private readonly PublicationVocabularyRepository $publicationVocabularyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnalyzePublicationHandler $analyzePublication,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/publications', name: 'publication_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('publication/index.html.twig', [
            'publications' => $this->publicationRepository->findAllOrderedByCreatedAt(),
        ]);
    }

    #[Route('/publications/new', name: 'publication_new', methods: ['GET'])]
    public function new(): Response
    {
        $form = $this->createForm(PublicationFormType::class, new PublicationInput(), [
            'action' => $this->generateUrl('publication_create'),
        ]);

        return $this->render('publication/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/publications', name: 'publication_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $input = new PublicationInput();
        $form = $this->createForm(PublicationFormType::class, $input, [
            'action' => $this->generateUrl('publication_create'),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('publication/new.html.twig', [
                'form' => $form->createView(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $publication = new Publication(
            title: trim((string) $input->title),
            type: $input->type,
            language: trim((string) $input->language),
            author: $this->blankToNull($input->author),
            rawText: trim((string) $input->rawText),
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        $this->addFlash('success', 'Publication created.');

        return $this->redirectToRoute('publication_show', [
            'id' => $publication->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/publications/{id}', name: 'publication_show', methods: ['GET'])]
    public function show(Publication $publication): Response
    {
        $vocabulary = $this->publicationVocabularyRepository->findForPublicationOrdered($publication);
        $occurrenceCount = array_sum(array_map(
            static fn ($row): int => $row->getOccurrences(),
            $vocabulary,
        ));

        return $this->render('publication/show.html.twig', [
            'publication' => $publication,
            'vocabulary' => $vocabulary,
            'summary' => [
                'uniqueVocabulary' => count($vocabulary),
                'vocabularyOccurrences' => $occurrenceCount,
            ],
            'textPreview' => $this->preview($publication->getRawText()),
        ]);
    }

    #[Route('/publications/{id}/analyze', name: 'publication_analyze', methods: ['POST'])]
    public function analyze(Publication $publication, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid($this->analyzeCsrfTokenId($publication), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            ($this->analyzePublication)($publication);
            $this->addFlash('success', 'Publication analyzed successfully.');
        } catch (PublicationAnalysisException|TextAnalyzerException $exception) {
            $this->logger->error('Publication analysis failed.', [
                'publication_id' => $publication->getId(),
                'exception' => $exception,
            ]);

            $message = 'Analysis failed.';
            if ((bool) $this->getParameter('kernel.debug')) {
                $message .= ' '.$exception->getMessage();
            }

            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('publication_show', [
            'id' => $publication->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    private function analyzeCsrfTokenId(Publication $publication): string
    {
        return 'analyze_publication_'.$publication->getId();
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function preview(?string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', (string) $text));

        if (mb_strlen($normalized) <= 360) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 360).'...';
    }
}
