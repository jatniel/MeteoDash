<?php

/**
 * SecurityHeadersListener.php.
 *
 * @author    Jatniel Guzmán <https://jatniel.dev>
 * @copyright 2026
 * @license   MIT
 */

declare(strict_types=1);

namespace App\EventListener;

use App\Service\CspNonce;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds security headers (including a nonce-based CSP) to every main response.
 */
#[AsEventListener(event: ResponseEvent::class)]
final readonly class SecurityHeadersListener
{
    public function __construct(
        private CspNonce $cspNonce,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Content-Security-Policy', \sprintf(
            "default-src 'self'; script-src 'self' 'nonce-%s'; style-src 'self' https://fonts.googleapis.com; "
            ."font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; "
            ."base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
            $this->cspNonce->get(),
        ));

        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }
}
