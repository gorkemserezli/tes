<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->isActive()) {
            return response()->json([
                'message' => 'Hesabınız aktif değil. Lütfen email doğrulaması yapın ve admin onayını bekleyin.'
            ], 403);
        }

        return $next($request);
    }
}
