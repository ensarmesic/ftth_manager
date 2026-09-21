<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $viteSource = '';
        if (app()->isLocal() && Vite::isRunningHot()) {
            $viteSource = ' '.rtrim(trim(file_get_contents(Vite::hotFile())), '/');
        }

        $contentSecurityPolicy = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src 'self' 'nonce-{$nonce}'".$viteSource,
            "style-src 'self' 'unsafe-inline'".$viteSource,
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:".$viteSource,
            "connect-src 'self' https: ws: wss:".$viteSource,
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "media-src 'self' blob:",
        ];
        if (app()->isProduction()) {
            $contentSecurityPolicy[] = 'upgrade-insecure-requests';
        }
        $response->headers->set('Content-Security-Policy', implode('; ', $contentSecurityPolicy));

        if ($request->user() && $this->isHtmlResponse($response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
