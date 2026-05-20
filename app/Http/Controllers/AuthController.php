<?php

/*
|--------------------------------------------------------------------------
| AuthController
|--------------------------------------------------------------------------
|
| Handles authentication for the MailGuard application.
| Users do not have their own credentials — they authenticate via Zoho OAuth.
|
| Flow:
|   1. The frontend obtains a Zoho OAuth access token through a popup/redirect.
|   2. POST /api/auth/login sends that token here.
|   3. We verify it by calling the Zoho API; on success we find-or-create the
|      local User record and issue a short-lived Laravel Sanctum token (55 min).
|   4. All subsequent requests send that Sanctum token as a Bearer header.
|   5. POST /api/auth/logout deletes the Sanctum token, ending the session.
|
| Every auth outcome (success and failure) is logged to the SIEM service.
|
*/

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // Inject SecurityEventService so every auth outcome is recorded in Firestore.
    public function __construct(private SecurityEventService $siem) {}

    /**
     * Exchange a valid Zoho OAuth token for a Sanctum session token.
     *
     * POST /api/auth/login
     * Body: { "token": "<zoho_access_token>" }
     */
    public function login(Request $request): JsonResponse
    {
        // Ensure the request body contains a non-empty token string.
        $request->validate(['token' => 'required|string']);

        // Call the Zoho Accounts API to verify the token and retrieve mailbox info.
        $zohoRes = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $request->input('token'),
        ])->get('https://mail.zoho.com/api/accounts');

        // If Zoho rejects the token (4xx/5xx), log the failure and return 401.
        if ($zohoRes->failed()) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'Zoho token rejected',
                'status' => $zohoRes->status(),
            ], 'medium');
            return response()->json(['error' => 'Invalid or expired Zoho token'], 401);
        }

        // Pull the first account object out of Zoho's "data" array.
        $account = $zohoRes->json('data.0');

        // A valid token can still return an empty account list — treat that as a failure.
        if (!$account) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'No Zoho account in response',
            ], 'medium');
            return response()->json(['error' => 'No Zoho account found for this token'], 401);
        }

        // Zoho uses different field names depending on account type; check both.
        $email = $account['mailboxAddress'] ?? $account['emailAddress'] ?? null;

        // If neither field is present we cannot identify the user — abort.
        if (!$email) {
            $this->siem->log('ZOHO_AUTH_FAILED', 'anonymous', [
                'reason' => 'Could not determine email from Zoho account',
            ], 'medium');
            return response()->json(['error' => 'Could not determine account email from Zoho'], 401);
        }

        // Find the local user by email, or create one on first login.
        // Password is a random string — login is Zoho-only, never password-based.
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name'  => $email, 'password' => Str::random(40)]
        );

        // Revoke any previous Sanctum tokens so only one session exists at a time.
        $user->tokens()->delete();

        // Issue a new Sanctum token that expires in 55 minutes, matching Zoho's
        // typical OAuth access token lifetime to keep the sessions in sync.
        $sanctumToken = $user->createToken(
            'zoho-session',
            ['*'],
            now()->addMinutes(55)
        );

        // Log the successful login to the SIEM for audit purposes.
        $this->siem->log('ZOHO_AUTH_SUCCESS', $email, [
            'accountId' => $account['accountId'] ?? null,
            'source'    => 'oauth_popup',
        ], 'low');

        // Return the Sanctum token and basic account info to the frontend.
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
        // Delete only the token used in this request, leaving other devices unaffected.
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true]);
    }
}
