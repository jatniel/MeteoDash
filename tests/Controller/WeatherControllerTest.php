<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for WeatherController and the security headers.
 *
 * These tests do NOT call the real OpenWeatherMap API.
 */
final class WeatherControllerTest extends WebTestCase
{
    private const array SAME_ORIGIN = [
        'HTTP_ORIGIN' => 'http://localhost',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];

    public function testHomePageRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'meteodash');
    }

    public function testWordmarkLinksToHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertSame('/', $client->getCrawler()->filter('h1.wordmark a')->attr('href'));
    }

    public function testHomePageSendsSecurityHeadersWithMatchingNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $headers = $client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertSame('geolocation=(), camera=(), microphone=()', $headers->get('Permissions-Policy'));
        $this->assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        $this->assertFalse($headers->has('Strict-Transport-Security'));

        $csp = (string) $headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $matches));
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);

        $nonce = $matches[1];
        $scripts = $client->getCrawler()->filter('script');
        $this->assertGreaterThan(0, $scripts->count());
        $scripts->each(function ($script) use ($nonce): void {
            $this->assertSame($nonce, $script->attr('nonce'));
        });

        // No inline styles or handlers that the CSP would block.
        $this->assertCount(0, $client->getCrawler()->filter('[style], style'));
    }

    public function testHomePageLoadsAssetsWithoutDataUrlsAndWithoutCityInUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();
        $crawler = $client->getCrawler();

        // Asset Mapper turns CSS imports into data: modules, which the CSP blocks.
        $this->assertStringNotContainsString('data:application/javascript', $html);
        $importmap = $crawler->filter('script[type="importmap"]');
        $this->assertSame(1, $importmap->count());
        $this->assertStringNotContainsString('data:', $importmap->text(null, false));

        $this->assertCount(1, $crawler->filter('link[rel="stylesheet"][href^="/assets/styles/app-"]'));
        $this->assertCount(1, $crawler->filter('link[rel="stylesheet"][href^="/assets/styles/weather-"]'));

        $form = $crawler->filter('form#search-form');
        $this->assertSame('post', strtolower((string) $form->attr('method')));
        $this->assertSame('/', $form->attr('action'));
        $this->assertNull($crawler->filter('#city-input')->attr('name'));
        $this->assertCount(0, $crawler->filter('[data-city]:not([type="button"])'));
    }

    public function testNativePostRedirectsToHomeWithoutQueryString(): void
    {
        $client = static::createClient();
        $client->request('POST', '/');

        $this->assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        $this->assertSame('/', $client->getResponse()->headers->get('Location'));
    }

    public function testHstsIsSentOnSecureRequests(): void
    {
        $client = static::createClient([], ['HTTPS' => 'on']);
        $client->request('GET', '/');

        $this->assertSame('max-age=31536000', $client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function testApiSuccess(): void
    {
        $client = static::createClient();
        $this->mockWeather();

        $this->postCity($client, 'Paris');

        $this->assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));

        $data = $this->json($client);
        $this->assertSame('Paris', $data['current']['city']);
        $this->assertCount(1, $data['forecast']);
    }

    public function testApiAcceptsAccentedCityNames(): void
    {
        $client = static::createClient();
        $this->mockWeather();

        $this->postCity($client, "L'Haÿ-les-Roses, Saint-Étienne");

        $this->assertResponseIsSuccessful();
    }

    public function testApiRejectsGet(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather', [], [], self::SAME_ORIGIN);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testApiRejectsMissingOriginHeaders(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/weather', [], [], ['CONTENT_TYPE' => 'application/json'], '{"city":"Paris"}');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testApiRejectsForeignOrigin(): void
    {
        $client = static::createClient();
        $this->postCity($client, 'Paris', ['HTTP_ORIGIN' => 'https://evil.example']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testApiRejectsCrossSiteFetchMetadata(): void
    {
        $client = static::createClient();
        $this->postCity($client, 'Paris', ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testApiRejectsWrongContentType(): void
    {
        $client = static::createClient();
        $this->postCity($client, 'Paris', ['CONTENT_TYPE' => 'text/plain']);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    public function testApiRejectsOversizedBody(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/weather', [], [], self::SAME_ORIGIN, json_encode(['city' => str_repeat('a', 2000)]));

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testApiRejectsMalformedJson(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/weather', [], [], self::SAME_ORIGIN, '{"city": ');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testApiRejectsMissingOrNonStringCity(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/weather', [], [], self::SAME_ORIGIN, '{"foo": "bar"}');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request('POST', '/api/weather', [], [], self::SAME_ORIGIN, '{"city": ["Paris"]}');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testApiRejectsInvalidCharacters(): void
    {
        $client = static::createClient();

        foreach (['Paris<script>', 'Paris;DROP', '../etc', '12345', "Pa\nris"] as $city) {
            $this->postCity($client, $city);
            $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, \sprintf('"%s" should be rejected', $city));
            $this->assertSame('Nom de ville invalide.', $this->json($client)['error']);
        }
    }

    public function testApiRejectsTooShortCity(): void
    {
        $client = static::createClient();
        $this->postCity($client, 'a');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertArrayHasKey('error', $this->json($client));
    }

    public function testApiReturnsNotFoundWhenCityIsUnknown(): void
    {
        $client = static::createClient();

        // Make the OpenWeatherMap call return 404 without hitting the real API,
        // so we exercise the controller's error mapping (404 -> HTTP_NOT_FOUND).
        static::getContainer()->set('http_client', new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]),
        ));

        $this->postCity($client, 'Atlantis');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertSame('Ville introuvable.', $this->json($client)['error']);
    }

    public function testBurstLimitReturns429WithRetryAfter(): void
    {
        $this->useLimit('WEATHER_BURST_LIMIT', 3);
        $client = static::createClient();
        $client->disableReboot(); // keep the in-memory limiter state between requests
        $this->mockWeather();

        for ($i = 0; $i < 3; ++$i) {
            $this->postCity($client, 'Paris');
            $this->assertResponseIsSuccessful();
        }

        $this->postCity($client, 'Paris');
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $headers = $client->getResponse()->headers;
        $retryAfter = (int) $headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter);
        $this->assertLessThanOrEqual(6, $retryAfter);
        $this->assertSame(\sprintf('Trop de recherches, réessayez dans %d s.', $retryAfter), $this->json($client)['error']);
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }

    public function testDailyIpLimitReturns429(): void
    {
        $this->useLimit('WEATHER_DAILY_IP_LIMIT', 2);
        $client = static::createClient();
        $client->disableReboot(); // keep the in-memory limiter state between requests
        $this->mockWeather();

        $this->postCity($client, 'Paris');
        $this->postCity($client, 'Paris');
        $this->assertResponseIsSuccessful();

        $this->postCity($client, 'Paris');
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertSame('Trop de recherches, réessayez plus tard.', $this->json($client)['error']);
        $this->assertGreaterThan(3600, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testGlobalLimitReturnsItsOwnMessage(): void
    {
        $this->useLimit('WEATHER_DAILY_GLOBAL_LIMIT', 1);
        $client = static::createClient();
        $client->disableReboot(); // keep the in-memory limiter state between requests
        $this->mockWeather();

        $this->postCity($client, 'Paris');
        $this->assertResponseIsSuccessful();

        $this->postCity($client, 'Paris');
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertSame('La démo a atteint sa limite quotidienne, réessayez demain.', $this->json($client)['error']);
        $this->assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testRateLimitIsCheckedAfterOriginButBeforeValidation(): void
    {
        $this->useLimit('WEATHER_BURST_LIMIT', 1);
        $client = static::createClient();
        $client->disableReboot(); // keep the in-memory limiter state between requests

        $this->postCity($client, 'a');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->postCity($client, 'Paris', ['HTTP_ORIGIN' => 'https://evil.example']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->postCity($client, 'a');
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** Lowers one limiter's limit (test config reads it from the environment). */
    private function useLimit(string $envVar, int $limit): void
    {
        putenv($envVar.'='.$limit);
        $_ENV[$envVar] = (string) $limit;
    }

    protected function tearDown(): void
    {
        foreach (['WEATHER_BURST_LIMIT', 'WEATHER_DAILY_IP_LIMIT', 'WEATHER_DAILY_GLOBAL_LIMIT'] as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }

        parent::tearDown();
    }

    /** @param array<string, string> $server */
    private function postCity(KernelBrowser $client, string $city, array $server = []): void
    {
        $client->request('POST', '/api/weather', [], [], $server + self::SAME_ORIGIN, json_encode(['city' => $city], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function mockWeather(): void
    {
        $current = json_encode([
            'name' => 'Paris',
            'sys' => ['country' => 'FR'],
            'main' => ['temp' => 20.0, 'feels_like' => 19.0, 'humidity' => 50, 'pressure' => 1015],
            'weather' => [['description' => 'ciel dégagé', 'icon' => '01d']],
            'wind' => ['speed' => 3.0, 'deg' => 90],
            'visibility' => 10000,
        ]);
        $forecast = json_encode([
            'city' => ['timezone' => 3600],
            'list' => [['dt' => 1700000000, 'main' => ['temp' => 18.0], 'weather' => [['description' => 'nuageux', 'icon' => '03d']]]],
        ]);

        static::getContainer()->set('http_client', new MockHttpClient(
            static fn (string $method, string $url): MockResponse => new MockResponse(
                str_contains($url, '/forecast') ? $forecast : $current,
                ['response_headers' => ['content-type: application/json']],
            ),
        ));
    }
}
