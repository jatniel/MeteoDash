<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    public function testApiReturnsNotFoundWhenCityIsUnknown(): void
    {
        $client = static::createClient();

        // Make the OpenWeatherMap call return 404 without hitting the real API,
        // so we exercise the controller's error mapping (404 -> HTTP_NOT_FOUND).
        $mockClient = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]),
        );
        static::getContainer()->set('http_client', $mockClient);

        $client->request('GET', '/api/weather/Atlantis');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('City not found.', $data['error']);
    }
}
