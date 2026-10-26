<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $remoteAddress = strtolower((string) $request->ip());
        $isLoopback = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

        abort_unless($isLoopback, 403, 'This local file organizer only accepts requests from this device.');

        return $next($request);
    }
}
