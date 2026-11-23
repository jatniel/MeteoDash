<?php

/**
 * CspExtension.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\Twig;

use App\Service\CspNonce;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the per-request CSP nonce to templates as csp_nonce().
 */
final readonly class CspExtension
{
    public function __construct(
        private CspNonce $cspNonce,
    ) {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->cspNonce->get();
    }
}
