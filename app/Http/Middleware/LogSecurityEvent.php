<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\SecurityEventService;

/**
 * LogSecurityEvent Middleware
 *
 * Automatically emits a structured security event to Firestore
 * after every relevant API request completes.
 *
 * Registration (app/Http/Kernel.php → $routeMiddleware):
 *   'siem.log' => \App\Http\Middleware\LogSecurityEvent::class,
 *
 * Usage in routes/api.php:
 *   Route::post('/zoho/sendEmailwAttachments', ...)->middleware('siem.log');
 *   Route::post('/zoho/accounts', ...)->middleware('siem.log');
 *
 * The middleware inspects the route path + HTTP method to decide
 * which event type and severity to emit. No changes needed in
 * individual controllers.
 *
 * Frontend must pass X-User-Id header on every request so events
 * are attributed to the correct user.
 */
class LogSecurityEvent
{
    public function __construct(private SecurityEventService $siem) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $userId   = $request->header('X-User-Id', 'anonymous');
        $method   = $request->method();
        $uri      = $request->path();
        $status   = $response->getStatusCode();
        $success  = $status < 400;

        [$eventType, $severity] = $this->classify($uri, $method, $status, $request);

        $metadata = [
            'method'     => $method,
            'path'       => $uri,
            'statusCode' => $status,
            'message'    => "{$method} /{$uri} → {$status}",
        ];

        // Attach extra context for file uploads
        if ($eventType === 'FILE_UPLOAD' || $eventType === 'FILE_REJECTED') {
            if ($file = $request->file('attachment') ?? $request->file('file')) {
                $metadata['filename']  = $file->getClientOriginalName();
                $metadata['mimeType']  = $file->getMimeType();
                $metadata['sizeBytes'] = $file->getSize();
            }
        }

        // Attach template info for email sends
        if ($eventType === 'EMAIL_SENT') {
            $metadata['template'] = $request->input('template', 'unknown');
            $metadata['company']  = $request->input('company',  'unknown');
        }

        $this->siem->log($eventType, $userId, $metadata, $severity);

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Route → Event Type mapping
    // ─────────────────────────────────────────────────────────────────────────
    private function classify(string $uri, string $method, int $status, Request $request): array
    {
        $success = $status < 400;

        // Zoho send email
        if (str_contains($uri, 'sendEmail')) {
            return $success
                ? ['EMAIL_SENT',         'low']
                : ['EMAIL_SEND_FAILED',  'medium'];
        }

        // Zoho OAuth token fetch
        if (str_contains($uri, 'zoho/accounts')) {
            return $success
                ? ['ZOHO_AUTH_SUCCESS',  'low']
                : ['ZOHO_TOKEN_FAILURE', 'medium'];
        }

        // File upload / attachment
        if (str_contains($uri, 'upload') || str_contains($uri, 'attachment')) {
            return $success
                ? ['FILE_UPLOAD',   'low']
                : ['FILE_REJECTED', 'medium'];
        }

        // Admin dashboard — company config changes
        if (str_contains($uri, 'companies') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return ['ADMIN_CONFIG_CHANGED', 'medium'];
        }

        // Default
        return ['API_CALL', 'low'];
    }
}
