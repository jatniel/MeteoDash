<?php

/**
 * WeatherController.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\Controller;

use App\Service\WeatherService;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Handles the weather dashboard page and its API endpoint.
 */
final class WeatherController extends AbstractController
{
    private const int MAX_BODY_BYTES = 1024;
    private const string CITY_PATTERN = '/^[\p{L}\p{M}][\p{L}\p{M} \'’.,-]*$/u';

    public function __construct(
        private readonly WeatherService $weatherService,
        #[Autowire(service: 'limiter.weather_api')]
        private readonly RateLimiterFactoryInterface $weatherApiLimiter,
    ) {
    }

    /**
     * Renders the search form (the AJAX frontend).
     *
     * A native form submission (JavaScript unavailable) is POSTed with no
     * field and answered with a redirect, so a city can never reach the URL.
     */
    #[Route('/', name: 'app_weather', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('app_weather', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('weather/index.html.twig');
    }

    /**
     * Returns weather data as JSON for the city given in the JSON body.
     *
     * The city is sent in a POST body (never in the URL) and the request is
     * hardened: same-origin check, rate limit, content type, size and input
     * validation. Only HttpClient exceptions are caught — unexpected errors
     * bubble up so they are not silently swallowed.
     */
    #[Route('/api/weather', name: 'api_weather', methods: ['POST'])]
    public function apiWeather(Request $request): JsonResponse
    {
        $response = $this->handleWeatherRequest($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function handleWeatherRequest(Request $request): JsonResponse
    {
        if (!$this->isSameOrigin($request)) {
            return $this->error('Requête non autorisée.', Response::HTTP_FORBIDDEN);
        }

        $limit = $this->weatherApiLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return $this->error(
                'Trop de requêtes, réessayez dans un instant.',
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error('Type de contenu non supporté, application/json requis.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error('Requête trop volumineuse.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, 4, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('Requête invalide.', Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($payload) || !isset($payload['city']) || !\is_string($payload['city'])) {
            return $this->error('Requête invalide.', Response::HTTP_BAD_REQUEST);
        }

        $city = trim($payload['city']);
        $length = mb_strlen($city);
        if ($length < 2 || $length > 100 || 1 !== preg_match(self::CITY_PATTERN, $city)) {
            return $this->error('Nom de ville invalide.', Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json([
                'current' => $this->weatherService->getWeather($city),
                'forecast' => $this->weatherService->getForecast($city),
            ]);
        } catch (ClientExceptionInterface $e) {
            return match ($e->getResponse()->getStatusCode()) {
                404 => $this->error('Ville introuvable.', Response::HTTP_NOT_FOUND),
                401 => $this->error('Erreur de configuration de l\'API.', Response::HTTP_INTERNAL_SERVER_ERROR),
                429 => $this->error('Trop de requêtes, réessayez dans un instant.', Response::HTTP_TOO_MANY_REQUESTS),
                default => $this->error('Impossible de récupérer les données météo.', Response::HTTP_BAD_GATEWAY),
            };
        } catch (ServerExceptionInterface|TransportExceptionInterface) {
            return $this->error('Service météo indisponible, réessayez dans un instant.', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (ExceptionInterface) {
            return $this->error('Une erreur inattendue est survenue.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Checks the request comes from this site's own page (CSRF / API key abuse).
     *
     * The JSON content-type requirement also forces a CORS preflight for
     * cross-origin callers, which this app never answers.
     */
    private function isSameOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');

        if (null === $fetchSite && null === $origin) {
            return false;
        }

        if (null !== $fetchSite && 'same-origin' !== $fetchSite) {
            return false;
        }

        return null === $origin || $origin === $request->getSchemeAndHttpHost();
    }

    /** @param array<string, string> $headers */
    private function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return $this->json(['error' => $message], $status, $headers);
    }
}
