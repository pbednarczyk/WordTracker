<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomeEndpointReportsHealthyServices(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your vocabulary');
        self::assertSelectorTextContains('body', 'Known words');
        self::assertSelectorTextContains('body', 'Due now');
        self::assertSelectorTextContains('body', 'Library progress');
        self::assertSelectorTextContains('body', 'Symfony');
        self::assertSelectorTextContains('body', 'PostgreSQL');
        self::assertSelectorTextContains('body', 'NLP service');
    }
}
