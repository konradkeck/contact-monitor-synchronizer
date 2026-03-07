<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = env('API_TOKEN', '');

        if (empty($token)) {
            return response()->json(['error' => 'API token not configured.'], 500);
        }

        $provided = $request->bearerToken();

        if (!$provided || !hash_equals($token, $provided)) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
