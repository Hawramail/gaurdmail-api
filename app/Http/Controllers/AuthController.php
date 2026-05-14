<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private SecurityEventService $siem) {}

    /**
     * Exchange a valid Zoho OAuth token for a Sanctum session token.
     *
     * POST /api/auth/login
     * Body: { "token": "<zoho_access_token>" }
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $zohoRes = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $request->input('token'),
        ])->get('https://mail.zoho.com/api/accounts');

        if ($zohoRes->failed()) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'Zoho token rejected',
                'status' => $zohoRes->status(),
            ], 'medium');
            return response()->json(['error' => 'Invalid or expired Zoho token'], 401);
        }

        $account = $zohoRes->json('data.0');

        if (!$account) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'No Zoho account in response',
            ], 'medium');
            return response()->json(['error' => 'No Zoho account found for this token'], 401);
        }

        $email = $account['mailboxAddress'] ?? $account['emailAddress'] ?? null;

        if (!$email) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'Could not determine email from Zoho account',
            ], 'medium');
            return response()->json(['error' => 'Could not determine account email from Zoho'], 401);
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name'  => $email, 'password' => Str::random(40)]
        );

        $user->tokens()->delete();
        $sanctumToken = $user->createToken(
            'zoho-session',
            ['*'],
            now()->addMinutes(55)
        );

        $this->siem->log('ZOHO_AUTH_SUCCESS', $email, [
            'accountId' => $account['accountId'] ?? null,
            'source'    => 'oauth_popup',
        ], 'low');

        return response()->json([
            'token'          => $sanctumToken->plainTextToken,
            'email'          => $email,
            'accountId'      => $account['accountId'],
            'mailboxAddress' => $email,
        ]);
    }

    /**
     * Revoke the current Sanctum token (logout).
     *
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true]);
    }
}
