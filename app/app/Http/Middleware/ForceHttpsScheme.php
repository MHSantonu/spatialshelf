<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generate https URLs whenever the request arrived over https.
 *
 * Behind cloudflared the app itself serves plain http; trusting the
 * proxy (see bootstrap/app.php) makes $request->secure() true, and this
 * pins every generated URL — forms, Livewire assets and update
 * endpoints, temporary upload URLs — to the same https scheme so
 * browsers stop blocking them as mixed content. Plain local http is
 * left alone. Runs after TrustProxies in the global stack.
 */
class ForceHttpsScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->secure()) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
