<?php

/**
 * WeatherData.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable value object representing weather data for a city.
 *
 * Implements JsonSerializable so the controller can return it directly
 * via $this->json() without manual field mapping.
 */
final readonly class WeatherData implements JsonSerializable
{
    public function __construct(
        public string $city,
        public string $country,
        public float $temperature,
        public float $feelsLike,
        public string $description,
        public string $icon,
        public int $humidity,
        public float $windSpeed,
        public string $windDirection,
        public int $visibility,
        public int $pressure,
    ) {
    }

    /**
     * @return array{
     *     city: string,
     *     country: string,
     *     temperature: float,
     *     feels_like: float,
     *     description: string,
     *     icon: string,
     *     humidity: int,
     *     wind_speed: float,
     *     wind_direction: string,
     *     visibility: int,
     *     pressure: int,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'city' => $this->city,
            'country' => $this->country,
            'temperature' => $this->temperature,
            'feels_like' => $this->feelsLike,
            'description' => $this->description,
            'icon' => $this->icon,
            'humidity' => $this->humidity,
            'wind_speed' => $this->windSpeed,
            'wind_direction' => $this->windDirection,
            'visibility' => $this->visibility,
            'pressure' => $this->pressure,
        ];
    }
}
