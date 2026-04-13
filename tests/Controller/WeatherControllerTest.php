<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for WeatherController.
 *
 * These tests do NOT call the real OpenWeatherMap API.
 * They only verify routing, rendering and input validation.
 */
final class WeatherControllerTest extends WebTestCase
{
    public function testHomePageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'meteodash');
    }

    public function testApiRejectsTooShortCity(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather/a');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
