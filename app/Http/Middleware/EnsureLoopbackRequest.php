<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureLoopbackRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('kbms.allow_non_loopback') || in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return $next($request);
        }

        Log::warning('kbms.loopback.rejected', ['ip' => $request->ip()]);

        abort(403);
    }
}
