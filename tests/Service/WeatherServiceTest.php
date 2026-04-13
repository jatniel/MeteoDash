<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\HourlyForecastData;
use App\DTO\WeatherData;
use App\Service\WeatherService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for WeatherService.
 *
 * Uses MockHttpClient — no real API calls, no API key needed.
 */
final class WeatherServiceTest extends TestCase
{
    public function testGetWeatherReturnsCorrectDto(): void
    {
        $apiResponse = [
            'name' => 'Paris',
            'sys' => ['country' => 'FR'],
            'main' => [
                'temp' => 18.5,
                'feels_like' => 17.2,
                'humidity' => 62,
                'pressure' => 1015,
            ],
            'weather' => [['description' => 'ciel dégagé', 'icon' => '01d']],
            'wind' => ['speed' => 3.5, 'deg' => 315],
            'visibility' => 10000,
        ];

        $service = $this->createService($apiResponse);
        $result = $service->getWeather('Paris');

        $this->assertInstanceOf(WeatherData::class, $result);
        $this->assertSame('Paris', $result->city);
        $this->assertSame('FR', $result->country);
        $this->assertSame(18.5, $result->temperature);
        $this->assertSame(17.2, $result->feelsLike);
        $this->assertSame('ciel dégagé', $result->description);
        $this->assertSame('01d', $result->icon);
        $this->assertSame(62, $result->humidity);
        $this->assertSame(3.5, $result->windSpeed);
        $this->assertSame('NW', $result->windDirection);
        $this->assertSame(10000, $result->visibility);
        $this->assertSame(1015, $result->pressure);
    }

    public function testGetForecastReturnsLimitedEntries(): void
    {
        $apiResponse = [
            'list' => [
                $this->createForecastEntry(1700000000, 20.0, 'nuageux', '04d'),
                $this->createForecastEntry(1700010800, 19.5, 'pluie légère', '10d'),
                $this->createForecastEntry(1700021600, 18.0, 'couvert', '04n'),
                $this->createForecastEntry(1700032400, 17.0, 'ciel dégagé', '01n'),
                $this->createForecastEntry(1700043200, 16.5, 'ciel dégagé', '01n'),
                $this->createForecastEntry(1700054000, 16.0, 'brume', '50n'),
            ],
        ];

        $service = $this->createService($apiResponse);
        $result = $service->getForecast('Paris', 5);

        $this->assertCount(5, $result);
        $this->assertContainsOnlyInstancesOf(HourlyForecastData::class, $result);
        $this->assertSame(20.0, $result[0]->temperature);
        $this->assertSame('nuageux', $result[0]->description);
    }

    private function createService(array $apiResponse): WeatherService
    {
        $mockResponse = new MockResponse(json_encode($apiResponse, JSON_THROW_ON_ERROR));
        $httpClient = new MockHttpClient($mockResponse);

        return new WeatherService($httpClient, 'fake-api-key');
    }

    private function createForecastEntry(int $dt, float $temp, string $desc, string $icon): array
    {
        return [
            'dt' => $dt,
            'main' => ['temp' => $temp],
            'weather' => [['description' => $desc, 'icon' => $icon]],
        ];
    }
}
