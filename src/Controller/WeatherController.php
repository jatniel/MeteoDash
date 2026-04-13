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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Handles the weather dashboard page and its API endpoint.
 */
final class WeatherController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /** Renders the search form (the AJAX frontend). */
    #[Route('/', name: 'app_weather')]
    public function index(): Response
    {
        return $this->render('weather/index.html.twig');
    }

    /**
     * Returns weather data as JSON for a given city.
     *
     * Only catches HttpClient exceptions — unexpected errors bubble up
     * so they are not silently swallowed.
     */
    #[Route('/api/weather/{city}', name: 'api_weather', methods: ['GET'])]
    public function apiWeather(string $city): JsonResponse
    {
        $city = trim($city);

        if (mb_strlen($city) < 2 || mb_strlen($city) > 100) {
            return $this->json(
                ['error' => 'Le nom de la ville doit contenir entre 2 et 100 caractères.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            return $this->json([
                'current' => $this->weatherService->getWeather($city),
                'forecast' => $this->weatherService->getForecast($city),
            ]);
        } catch (ExceptionInterface) {
            return $this->json(
                ['error' => 'Impossible de récupérer la météo pour cette ville.'],
                Response::HTTP_NOT_FOUND,
            );
        }
    }
}
