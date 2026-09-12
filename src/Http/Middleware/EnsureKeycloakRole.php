<?php

namespace Syifa\KeycloakSso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Penggunaan: Route::middleware('keycloak.role:hr-admin')->group(...) */
class EnsureKeycloakRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $granted = session('keycloak.roles', []);

        if (empty(array_intersect($roles, $granted))) {
            abort(403, 'Anda tidak memiliki role Keycloak yang diperlukan: ' . implode(', ', $roles));
        }

        return $next($request);
    }
}
