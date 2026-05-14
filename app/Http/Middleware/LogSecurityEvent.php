<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\SecurityEventService;

class LogSecurityEvent
{
    public function __construct(private SecurityEventService $siem) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        // Identity comes from the verified Sanctum session — not a client header.
        $userId  = $request->user()?->email ?? 'anonymous';
        $method  = $request->method();
        $uri     = $request->path();
        $status  = $response->getStatusCode();

        [$eventType, $severity] = $this->classify($uri, $method, $status);

        $metadata = [
            'method'     => $method,
            'path'       => $uri,
            'statusCode' => $status,
        ];

        // Enrich email-send events with business context passed in the request body
        if ($eventType === 'EMAIL_SENT' || $eventType === 'EMAIL_SEND_FAILED') {
            $metadata['template'] = $request->input('template', 'unknown');
            $metadata['company']  = $request->input('company',  'unknown');
        }

        // Enrich file events with file metadata
        if ($eventType === 'FILE_UPLOAD' || $eventType === 'FILE_REJECTED') {
            $file = $request->file('file') ?? $request->file('attachment');
            if ($file) {
                $metadata['filename'] = $file->getClientOriginalName();
                $metadata['fileSize'] = $file->getSize();
                $metadata['mimeType'] = $file->getMimeType();
            }
        }

        $this->siem->log($eventType, $userId, $metadata, $severity);

        return $response;
    }

    private function classify(string $uri, string $method, int $status): array
    {
        $ok = $status < 400;

        if (str_contains($uri, 'sendEmail')) {
            return $ok ? ['EMAIL_SENT', 'low'] : ['EMAIL_SEND_FAILED', 'medium'];
        }

        if (str_contains($uri, 'zoho/accounts')) {
            return $ok ? ['ZOHO_ACCOUNT_FETCH', 'low'] : ['ZOHO_TOKEN_FAILURE', 'medium'];
        }

        if (str_contains($uri, 'ocr')) {
            return $ok ? ['FILE_UPLOAD', 'low'] : ['FILE_REJECTED', 'medium'];
        }

        if (str_contains($uri, 'upload') || str_contains($uri, 'attachment')) {
            return $ok ? ['FILE_UPLOAD', 'low'] : ['FILE_REJECTED', 'medium'];
        }

        if (str_contains($uri, 'companies') && \in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return ['ADMIN_CONFIG_CHANGED', 'medium'];
        }

        return ['API_CALL', 'low'];
    }
}
