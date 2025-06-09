<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyApproved
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip for admin users
        if ($user && $user->isAdmin()) {
            return $next($request);
        }

        // Check company approval for customers
        if (!$user || !$user->isApproved()) {
            return response()->json([
                'message' => 'Şirketiniz henüz onaylanmamış. Lütfen admin onayını bekleyin.'
            ], 403);
        }

        return $next($request);
    }
}
