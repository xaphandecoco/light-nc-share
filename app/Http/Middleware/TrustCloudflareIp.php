<?php

namespace App\Http\Middleware;

use App\Services\AnonymousLimits;
use Closure;
use Illuminate\Http\Request;

/**
 * Makes $request->ip() the visitor's address when the request arrives through the tunnel.
 * CF-Connecting-IP is believed only when the direct peer is a private address.
 */
class TrustCloudflareIp
{
    public function handle(Request $request, Closure $next)
    {
        $peer = $request->server->get('REMOTE_ADDR');
        $cf = $request->headers->get('CF-Connecting-IP');

        if (is_string($peer) && $cf !== null) {
            $request->server->set('REMOTE_ADDR', AnonymousLimits::clientIp($peer, $cf));
        }

        return $next($request);
    }
}
