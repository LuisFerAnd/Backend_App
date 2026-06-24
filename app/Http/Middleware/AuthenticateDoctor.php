<?php

namespace App\Http\Middleware;

use App\Models\DoctorToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDoctor
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return response()->json([
                'message' => 'Token de autenticacion requerido.',
            ], 401);
        }

        $doctorToken = DoctorToken::with('doctor')
            ->where('token', hash('sha256', $plainToken))
            ->first();

        if (! $doctorToken || ! $doctorToken->doctor) {
            return response()->json([
                'message' => 'Token de autenticacion invalido.',
            ], 401);
        }

        $doctorToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        $request->attributes->set('doctor_token', $doctorToken);
        $request->setUserResolver(fn () => $doctorToken->doctor);

        return $next($request);
    }
}
