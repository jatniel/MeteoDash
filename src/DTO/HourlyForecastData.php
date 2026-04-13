<?php

/**
 * HourlyForecastData.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable value object representing a single forecast entry (every 3 hours).
 */
final readonly class HourlyForecastData implements JsonSerializable
{
    public function __construct(
        public string $time,
        public float $temperature,
        public string $description,
        public string $icon,
    ) {
    }

    /** @return array{time: string, temperature: float, description: string, icon: string} */
    public function jsonSerialize(): array
    {
        return [
            'time' => $this->time,
            'temperature' => $this->temperature,
            'description' => $this->description,
            'icon' => $this->icon,
        ];
    }
}
