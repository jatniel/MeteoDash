<?php

/**
 * WeatherService.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\Service;

use App\DTO\HourlyForecastData;
use App\DTO\WeatherData;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches weather data from the OpenWeatherMap API.
 *
 * The API key is injected via #[Autowire] to avoid manual wiring in services.yaml.
 */
final readonly class WeatherService
{
    private const string BASE_URL = 'https://api.openweathermap.org/data/2.5';
    private const int CACHE_TTL = 600; // 10 minutes

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'OPENWEATHERMAP_API_KEY')]
        private string $openweathermapApiKey,
        #[Autowire(env: 'WEATHER_LANG')]
        private string $lang,
        #[Autowire(env: 'WEATHER_UNITS')]
        private string $units,
    ) {
    }

    /**
     * @throws InvalidArgumentException on network or API error
     */
    public function getWeather(string $city): WeatherData
    {
        $data = $this->request('/weather', $city);

        return new WeatherData(
            city: $data['name'],
            country: $data['sys']['country'] ?? '',
            temperature: $data['main']['temp'],
            feelsLike: $data['main']['feels_like'],
            description: $data['weather'][0]['description'],
            icon: $data['weather'][0]['icon'],
            humidity: $data['main']['humidity'],
            windSpeed: $data['wind']['speed'],
            windDirection: self::degreesToDirection($data['wind']['deg'] ?? 0),
            visibility: $data['visibility'] ?? 0,
            pressure: $data['main']['pressure'],
        );
    }

    /**
     * Returns the next forecast entries (every 3 hours) for a given city.
     *
     * @return HourlyForecastData[]
     *
     * @throws InvalidArgumentException on network or API error
     */
    public function getForecast(string $city, int $limit = 5): array
    {
        $data = $this->request('/forecast', $city);

        return array_map(
            static fn (array $entry) => new HourlyForecastData(
                time: date('H:i', $entry['dt']),
                temperature: $entry['main']['temp'],
                description: $entry['weather'][0]['description'],
                icon: $entry['weather'][0]['icon'],
            ),
            \array_slice($data['list'], 0, $limit),
        );
    }

    /** Converts wind degrees (0-360) to a cardinal direction (N, NE, E…). */
    private static function degreesToDirection(int $degrees): string
    {
        $directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

        return $directions[(int) round($degrees / 45) % 8];
    }

    /** @return array<string, mixed> Shared request logic with cache
     * @throws InvalidArgumentException
     */
    private function request(string $endpoint, string $city): array
    {
        $cacheKey = 'meteodash_'.md5($endpoint.strtolower($city).$this->lang.$this->units);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($endpoint, $city): array {
            $item->expiresAfter(self::CACHE_TTL);

            $response = $this->httpClient->request('GET', self::BASE_URL.$endpoint, [
                'query' => [
                    'q' => $city,
                    'appid' => $this->openweathermapApiKey,
                    'units' => $this->units,
                    'lang' => $this->lang,
                ],
            ]);

            return $response->toArray();
        });
    }
}
