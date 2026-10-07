<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the private app out of search indexes. Only the landing page and the
 * public surfaces (status pages, uptime badges) stay indexable.
 *
 * A header rather than robots.txt Disallow: a disallowed URL is never fetched,
 * so a crawler would never see the noindex and could still list the bare URL.
 */
class NoIndexPrivatePages
{
    /** @var list<string> */
    private const PUBLIC_PATHS = ['/', 'status/*', 'badge/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is(...self::PUBLIC_PATHS)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
