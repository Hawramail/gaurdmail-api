<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\SecurityEventService;

class SiemController extends Controller
{
    public function __construct(private SecurityEventService $siem) {}

    public function stats(): JsonResponse
    {
        $since = now()->subHours(24)->toIso8601String();

        return response()->json([
            'emailsSent'      => count($this->siem->getRecentEvents('EMAIL_SENT',       1440)),
            'emailsSentDelta' => 'Last 24 hours',
            'fileUploads'     => count($this->siem->getRecentEvents('FILE_UPLOAD',       1440)),
            'fileUploadsSub'  => 'Last 24 hours',
            'anomalies'       => count($this->siem->getRecentEvents('ANOMALY_DETECTED', 1440)),
            'anomaliesSub'    => 'Requires review',
        ]);
    }

    public function logEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'eventType' => 'required|string|max:60',
            'userId'    => 'required|string|max:120',
            'metadata'  => 'sometimes|array',
            'severity'  => 'sometimes|in:low,medium,high,critical',
        ]);

        $this->siem->log($data['eventType'], $data['userId'], $data['metadata'] ?? [], $data['severity'] ?? 'low');
        return response()->json(['success' => true]);
    }

    public function acknowledge(string $alertId): JsonResponse
    {
        try {
            $this->siem->updateAlertStatus($alertId, 'acknowledged');
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function resolve(string $alertId): JsonResponse
    {
        try {
            $this->siem->updateAlertStatus($alertId, 'resolved');
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}