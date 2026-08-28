<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly string $nlpHealthUrl,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $databaseOk = $this->checkDatabase();
        $nlpOk = $this->checkNlpService();

        return $this->render('home/index.html.twig', [
            'database_ok' => $databaseOk,
            'nlp_ok' => $nlpOk,
        ], new Response(status: $databaseOk && $nlpOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private function checkDatabase(): bool
    {
        try {
            return (string) $this->connection->fetchOne('SELECT 1') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkNlpService(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->nlpHealthUrl, [
                'timeout' => 2.0,
            ]);

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                return false;
            }

            $payload = $response->toArray(false);

            return ($payload['status'] ?? null) === 'ok'
                && ($payload['service'] ?? null) === 'wordtracker-nlp';
        } catch (\Throwable) {
            return false;
        }
    }
}
