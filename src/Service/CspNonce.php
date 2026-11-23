<?php

/**
 * CspNonce.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the Content-Security-Policy nonce of the current request.
 *
 * The same value is printed on <script> tags (Twig) and sent in the CSP header.
 * It is generated lazily, once per request, and reset between requests.
 */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    public function get(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
