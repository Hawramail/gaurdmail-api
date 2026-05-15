<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * SecurityEventService — Firestore REST API version
 *
 * Uses the Firestore REST API over HTTP instead of the kreait/firebase-php SDK.
 * This avoids requiring the grpc and sodium PHP extensions entirely.
 *
 * Add to .env:
 *   FIREBASE_PROJECT_ID=mailgaurd-2d6dc
 *   FIREBASE_API_KEY=your-web-api-key   ← Firebase Console → Project Settings → General → Web API Key
 */
class SecurityEventService
{
    private string $projectId;
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->projectId = env('FIREBASE_PROJECT_ID', 'mailgaurd-2d6dc');
        $this->apiKey    = env('FIREBASE_API_KEY', '');
        $this->baseUrl   = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents";
    }

    public function log(
        string  $eventType,
        string  $userId,
        array   $metadata    = [],
        string  $severity    = 'low',
        ?string $anomalyRule = null
    ): void {
        $this->addDocument('security_events', [
            'eventType'            => $eventType,
            'userId'               => $userId,
            'metadata'             => $metadata,
            'severity'             => $severity,
            'anomalyRuleTriggered' => $anomalyRule,
            'timestamp'            => now()->toIso8601String(),
        ]);
    }

    public function createAlert(
        string $rule,
        string $description,
        string $severity,
        string $userId,
        array  $metadata = []
    ): void {
        $this->addDocument('alerts', [
            'rule'           => $rule,
            'description'    => $description,
            'severity'       => $severity,
            'userId'         => $userId,
            'metadata'       => $metadata,
            'status'         => 'open',
            'acknowledgedAt' => null,
            'resolvedAt'     => null,
            'timestamp'      => now()->toIso8601String(),
        ]);
    }

    public function updateAlertStatus(string $alertId, string $status): void
    {
        $field = $status === 'acknowledged' ? 'acknowledgedAt' : 'resolvedAt';
        $url   = "{$this->baseUrl}/alerts/{$alertId}"
               . "?key={$this->apiKey}"
               . "&updateMask.fieldPaths=status"
               . "&updateMask.fieldPaths={$field}";

        Http::patch($url, [
            'fields' => $this->encodeFields([
                'status' => $status,
                $field   => now()->toIso8601String(),
            ])
        ]);
    }

    public function getRecentEvents(string $eventType, int $minutes = 60, ?string $userId = null): array
{
    $since = now('UTC')->subMinutes($minutes);

    $filters = [
        ['fieldFilter' => ['field' => ['fieldPath' => 'eventType'], 'op' => 'EQUAL',
                           'value' => ['stringValue' => $eventType]]],
    ];

    if ($userId) {
        $filters[] = ['fieldFilter' => ['field' => ['fieldPath' => 'userId'], 'op' => 'EQUAL',
                                        'value' => ['stringValue' => $userId]]];
    }

    $query = count($filters) === 1
        ? ['fieldFilter' => $filters[0]['fieldFilter']]
        : ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]];

    $response = Http::post("{$this->baseUrl}:runQuery?key={$this->apiKey}", [
        'structuredQuery' => [
            'from'  => [['collectionId' => 'security_events']],
            'where' => $query,
        ]
    ]);

    if (!$response->successful()) return [];

    $results = [];
    foreach ($response->json() as $item) {
        if (!isset($item['document'])) continue;
        $decoded = array_merge(
            ['_id' => basename($item['document']['name'])],
            $this->decodeFields($item['document']['fields'] ?? [])
        );
        // Filter by time in PHP
        $ts = $decoded['timestamp'] ?? null;
        if ($ts) {
            $eventTime = new \DateTime($ts);
            if ($eventTime < $since) continue;
        }
        $results[] = $decoded;
    }
    return $results;
}

    public function openAlertExistsForRule(string $rule): bool
    {
        $response = Http::post("{$this->baseUrl}:runQuery?key={$this->apiKey}", [
            'structuredQuery' => [
                'from'  => [['collectionId' => 'alerts']],
                'where' => [
                    'compositeFilter' => [
                        'op' => 'AND',
                        'filters' => [
                            ['fieldFilter' => ['field' => ['fieldPath' => 'rule'],   'op' => 'EQUAL', 'value' => ['stringValue' => $rule]]],
                            ['fieldFilter' => ['field' => ['fieldPath' => 'status'], 'op' => 'EQUAL', 'value' => ['stringValue' => 'open']]],
                        ]
                    ]
                ],
                'limit' => 1,
            ]
        ]);

        if (!$response->successful()) return false;
        foreach ($response->json() as $item) {
            if (isset($item['document'])) return true;
        }
        return false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addDocument(string $collection, array $data): void
    {
        Http::post(
            "{$this->baseUrl}/{$collection}?key={$this->apiKey}",
            ['fields' => $this->encodeFields($data)]
        );
    }

    private function encodeFields(array $data): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[$key] = $this->encodeValue($value);
        }
        return $fields;
    }

    private function encodeValue(mixed $value): array
    {
        if (is_null($value))  return ['nullValue'  => null];
        if (is_bool($value))  return ['booleanValue' => $value];
        if (is_int($value))   return ['integerValue' => (string) $value];
        if (is_float($value)) return ['doubleValue' => $value];
        if (is_array($value)) {
            if (array_is_list($value)) {
                return ['arrayValue' => ['values' => array_map([$this, 'encodeValue'], $value)]];
            }
            return ['mapValue' => ['fields' => $this->encodeFields($value)]];
        }
        return ['stringValue' => (string) $value];
    }

    private function decodeFields(array $fields): array
    {
        $data = [];
        foreach ($fields as $key => $value) {
            $data[$key] = $this->decodeValue($value);
        }
        return $data;
    }

    private function decodeValue(array $value): mixed
    {
        if (isset($value['stringValue']))    return $value['stringValue'];
        if (isset($value['integerValue']))   return (int)   $value['integerValue'];
        if (isset($value['doubleValue']))    return (float) $value['doubleValue'];
        if (isset($value['booleanValue']))   return (bool)  $value['booleanValue'];
        if (isset($value['nullValue']))      return null;
        if (isset($value['timestampValue'])) return $value['timestampValue'];
        if (isset($value['mapValue']))       return $this->decodeFields($value['mapValue']['fields'] ?? []);
        if (isset($value['arrayValue']))     return array_map([$this, 'decodeValue'], $value['arrayValue']['values'] ?? []);
        return null;
    }
}