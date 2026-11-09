<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Si la prueba o el plan del taller se venció (o está suspendido), la API responde 402 y Angular lleva a la pantalla de suscripción. */
class SuscripcionVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && ! $u->esSuperadmin() && ! $u->taller?->vigente()) {
            return response()->json(['message' => 'La suscripción de tu taller no está vigente.', 'suscripcion' => true], 402);
        }

        return $next($request);
    }
}
