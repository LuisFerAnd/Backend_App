<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $doctor = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return response()->json(
            $this->createTokenResponse($doctor, $data['device_name'] ?? 'mobile'),
            201
        );
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $doctor = User::where('email', $data['email'])->first();

        if (! $doctor || ! Hash::check($data['password'], $doctor->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        return response()->json(
            $this->createTokenResponse($doctor, $data['device_name'] ?? 'mobile')
        );
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'doctor' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('doctor_token')?->delete();

        return response()->json([
            'message' => 'Sesion cerrada correctamente.',
        ]);
    }

    private function createTokenResponse(User $doctor, string $deviceName): array
    {
        $plainToken = Str::random(80);

        $doctor->tokens()->create([
            'name' => $deviceName,
            'token' => hash('sha256', $plainToken),
        ]);

        return [
            'doctor' => $doctor,
            'token' => $plainToken,
            'token_type' => 'Bearer',
        ];
    }
}
