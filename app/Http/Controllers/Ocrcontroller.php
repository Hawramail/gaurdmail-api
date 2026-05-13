<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrController extends Controller
{
    /**
     * Extract text from an uploaded document via OCR.space API,
     * then parse insurance-relevant fields from the raw text.
     *
     * POST /api/ocr/extract
     * Body: multipart/form-data  { file: <image|pdf> }
     * Returns: { success, rawText, fields: { ... } }
     */
    public function extract(Request $request)
    {
        // ── 1. Validate ──────────────────────────────────────────────────────
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf,bmp,tiff|max:5120', 
        ]);

        $file    = $request->file('file');
        $apiKey  = env('OCR_SPACE_API_KEY', 'K82551922288957'); 

        // ── 2. Call OCR.space via base64 (more reliable than multipart upload) ──
        try {
            $mimeType  = $file->getMimeType() ?: 'image/jpeg';
            $base64    = base64_encode(file_get_contents($file->getPathname()));
            $dataUri   = "data:{$mimeType};base64,{$base64}";

            $response = Http::timeout(60)->post('https://api.ocr.space/parse/image', [
                'apikey'            => $apiKey,
                'base64Image'       => $dataUri,
                'language'          => 'eng',
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'scale'             => 'true',
                'OCREngine'         => '1',
                'filetype'          => strtoupper(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)) ?: 'JPG',
            ]);

            $data = $response->json();

            // Always log the raw OCR.space response so mismatches can be diagnosed
            Log::info('OCR.space response', [
                'exitCode'  => $data['OCRExitCode']          ?? null,
                'errored'   => $data['IsErroredOnProcessing'] ?? null,
                'errorMsg'  => $data['ErrorMessage']          ?? null,
                'parsed'    => isset($data['ParsedResults'][0])
                    ? substr($data['ParsedResults'][0]['ParsedText'] ?? '', 0, 500)
                    : null,
            ]);

            // API-level error (bad key, unsupported file, quota exceeded, etc.)
            if (! empty($data['IsErroredOnProcessing'])) {
                $apiMsg = is_array($data['ErrorMessage'])
                    ? implode(' ', $data['ErrorMessage'])
                    : ($data['ErrorMessage'] ?? 'Unknown OCR.space error');
                return response()->json([
                    'success' => false,
                    'message' => "OCR service error: {$apiMsg}",
                ], 422);
            }

            if (! isset($data['ParsedResults'][0])) {
                return response()->json([
                    'success' => false,
                    'message' => 'OCR returned no results. The file may be blank or unreadable.',
                ], 422);
            }

            $rawText = $data['ParsedResults'][0]['ParsedText'] ?? '';

            if (empty(trim($rawText))) {
                return response()->json([
                    'success' => false,
                    'message' => 'No text could be extracted from the document.',
                ], 422);
            }

        } catch (\Exception $e) {
            Log::error('OCR API error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reach OCR service: ' . $e->getMessage(),
            ], 502);
        }


        $fields = $this->parseInsuranceFields($rawText);

        return response()->json([
            'success' => true,
            'rawText' => $rawText,
            'fields'  => $fields,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Field Extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Strings that must never be returned as a name, regardless of which
     * pattern matched them. Bahraini documents always contain these headers.
     */
    private const NAME_BLOCKLIST = [
        'kingdom of bahrain',
        'ministry of interior',
        'general directorate of traffic',
        'general directorate',
        'directorate of traffic',
        'bahrain',
        'insurance company',
        'insurance co',
        'towergate',
        'gig gulf',
        'solidarity',
        'arab insurance',
        'batelco',
    ];

    /**
     * Extract structured insurance fields from raw OCR text.
     * Returns an associative array — null values mean "not found".
     * All keys match the template variable names used in emailTemplates.js.
     */
    private function parseInsuranceFields(string $text): array
    {
        // Normalise line endings and collapse runs of spaces/tabs
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Helper: build a pattern that matches "Label[:\s]\nValue" (next line)
        // as well as the inline "Label: Value" form — used inline below via closure.

        return [
            // ── Identity ──────────────────────────────────────────────────────
            'insuredName' => $this->extractName($text),

            // Bahraini CPR: 9 digits (century + 8). Accept 8–10 for OCR noise.
            'cprNumber' => $this->matchPattern($text, [
                '/(?:CPR|C\.P\.R\.?|Civil\s*(?:ID|No\.?))\s*[:\-#]?\s*(\d{8,10})/i',
                // next-line: label on one line, value on the next
                '/(?:CPR|C\.P\.R\.?|Civil\s*(?:ID|No\.?))\s*[:\-#]?\s*\n\s*(\d{8,10})/im',
                '/\b((?:19|20)\d{7,8})\b/',
            ]),

            'crNumber' => $this->matchPattern($text, [
                '/(?:C\.?R\.?\s*(?:No\.?|Number|#)|Commercial\s+Reg(?:istration)?(?:\s*No\.?)?)\s*[:\-]?\s*(\d{4,10})/i',
                '/(?:C\.?R\.?\s*(?:No\.?|Number|#)|Commercial\s+Reg(?:istration)?(?:\s*No\.?)?)\s*[:\-]?\s*\n\s*(\d{4,10})/im',
            ]),

            // ── Policy ────────────────────────────────────────────────────────
            'policyNumber' => $this->matchPattern($text, [
                '/(?:policy\s*(?:no\.?|number|#)|pol\.?\s*no\.?)\s*[:\-]?\s*([A-Z0-9][\w\-\/]{3,24})/i',
                '/(?:policy\s*(?:no\.?|number|#)|pol\.?\s*no\.?)\s*[:\-]?\s*\n\s*([A-Z0-9][\w\-\/]{3,24})/im',
                '/(?:certificate|cert\.?)\s*(?:no\.?|number|#)\s*[:\-]?\s*([A-Z0-9][\w\-\/]{3,24})/i',
                '/(?:certificate|cert\.?)\s*(?:no\.?|number|#)\s*[:\-]?\s*\n\s*([A-Z0-9][\w\-\/]{3,24})/im',
            ]),

            'certificateNumber' => $this->matchPattern($text, [
                '/(?:certificate|cert\.?)\s*(?:no\.?|number|#)\s*[:\-]?\s*([A-Z0-9][\w\-\/]{3,24})/i',
                '/(?:certificate|cert\.?)\s*(?:no\.?|number|#)\s*[:\-]?\s*\n\s*([A-Z0-9][\w\-\/]{3,24})/im',
            ]),

            'coverType' => $this->matchPattern($text, [
                '/\b(comprehensive|third[\s\-]party(?:\s+only)?|fire\s+(?:and|&)\s+theft|TP\s+only)\b/i',
                // Abbreviated forms common on Bahraini docs
                '/\b(comp|tpl|tpo)\b/i',
            ]),

            // ── Dates — Bahrain docs use DD/MM/YYYY ───────────────────────────
            'effectiveDate' => $this->extractDate($text, [
                '/(?:effective\s*date|inception\s*date|commencement\s*date|start\s*date|from\s*date|issue\s*date|date\s*of\s*issue)\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
                '/(?:effective\s*date|inception\s*date|commencement\s*date|start\s*date|from\s*date)\s*[:\-]?\s*\n\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/im',
                '/\bfrom\b\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
                '/period\s*(?:of\s*insurance)?\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
            ]),

            'expiryDate' => $this->extractDate($text, [
                '/(?:expiry\s*date|expiration\s*date|exp(?:iry|ires)?\s*(?:date)?|valid\s*(?:until|to)|to\s*date)\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
                '/(?:expiry\s*date|expiration\s*date|exp(?:iry|ires)?\s*(?:date)?|valid\s*(?:until|to))\s*[:\-]?\s*\n\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/im',
                '/\bto\b\s*[:\-]?\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
                '/period\s*(?:of\s*insurance)?\s*[:\-]?\s*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}\s*[\-–to]+\s*(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})/i',
            ]),

            // ── Vehicle ───────────────────────────────────────────────────────
            'vehicleMake' => $this->matchPattern($text, [
                '/(?:make|manufacturer|brand|type\s*of\s*vehicle|vehicle\s*make)\s*[:\-]?\s*([A-Za-z]{2,20})/i',
                '/(?:make|manufacturer|brand|type\s*of\s*vehicle)\s*[:\-]?\s*\n\s*([A-Za-z]{2,20})/im',
            ]),

            'vehicleModel' => $this->matchPattern($text, [
                '/(?:model)\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9\s\-]{1,28})/i',
                '/(?:model)\s*[:\-]?\s*\n\s*([A-Za-z0-9][A-Za-z0-9\s\-]{1,28})/im',
            ]),

            'vehicleYear' => $this->matchPattern($text, [
                '/(?:year\s*of\s*(?:make|manufacture|mfg)|model\s*year|m\.?y\.?|year)\s*[:\-]?\s*(20\d{2}|19\d{2})/i',
                '/(?:year\s*of\s*(?:make|manufacture|mfg)|model\s*year|m\.?y\.?)\s*[:\-]?\s*\n\s*(20\d{2}|19\d{2})/im',
                '/^(20[012]\d|199\d)\s*$/m',
            ]),

            // Bahrain plates: 4–6 digits, optional letter prefix/suffix
            'plateNumber' => $this->matchPattern($text, [
                '/(?:plate\s*(?:no\.?|number|#)|registration\s*(?:no\.?|number)|reg\.?\s*no\.?|licence\s*plate)\s*[:\-]?\s*([A-Z]?\s*\d{4,6}[A-Z]?)/i',
                '/(?:plate\s*(?:no\.?|number|#)|registration\s*(?:no\.?|number)|reg\.?\s*no\.?)\s*[:\-]?\s*\n\s*([A-Z]?\s*\d{4,6}[A-Z]?)/im',
                '/\bplate\b.*?(\d{4,6})/is',
            ]),

            // Chassis: 17-char VIN preferred; fall back to labelled shorter codes
            'chassisNumber' => $this->matchPattern($text, [
                '/(?:chassis|VIN|frame)\s*(?:no\.?|number|#)?\s*[:\-]?\s*([A-HJ-NPR-Z0-9]{17})/i',
                '/(?:chassis|VIN|frame)\s*(?:no\.?|number|#)?\s*[:\-]?\s*\n\s*([A-HJ-NPR-Z0-9]{17})/im',
                '/(?:chassis|frame)\s*(?:no\.?|number|#)?\s*[:\-]?\s*([A-Z0-9]{8,17})/i',
                '/(?:chassis|frame)\s*(?:no\.?|number|#)?\s*[:\-]?\s*\n\s*([A-Z0-9]{8,17})/im',
            ]),

            'engineNumber' => $this->matchPattern($text, [
                '/(?:engine)\s*(?:no\.?|number|#)?\s*[:\-]?\s*([A-Z0-9]{5,20})/i',
                '/(?:engine)\s*(?:no\.?|number|#)?\s*[:\-]?\s*\n\s*([A-Z0-9]{5,20})/im',
            ]),

            // ── Financial ─────────────────────────────────────────────────────
            'sumInsured' => $this->matchPattern($text, [
                '/(?:sum\s+insured|insured\s+value|vehicle\s+value|agreed\s+value|market\s+value)\s*[:\-]?\s*(?:BD|BHD|USD|BHD\.?)?\s*([\d,]+(?:\.\d{1,3})?)/i',
                '/(?:sum\s+insured|insured\s+value|vehicle\s+value|agreed\s+value|market\s+value)\s*[:\-]?\s*\n\s*(?:BD|BHD|USD)?\s*([\d,]+(?:\.\d{1,3})?)/im',
            ]),

            'premium' => $this->matchPattern($text, [
                '/(?:(?:total\s+)?premium|net\s+premium|gross\s+premium)\s*[:\-]?\s*(?:BD|BHD|USD)?\s*([\d,]+(?:\.\d{1,3})?)/i',
                '/(?:(?:total\s+)?premium|net\s+premium|gross\s+premium)\s*[:\-]?\s*\n\s*(?:BD|BHD|USD)?\s*([\d,]+(?:\.\d{1,3})?)/im',
            ]),

            // ── Contact ───────────────────────────────────────────────────────
            'phone' => $this->matchPattern($text, [
                '/(?:tel(?:ephone)?|mobile|mob\.?|phone|contact\s*(?:no\.?)?)\s*[:\-]?\s*(\+?[\d\s\-\(\)]{7,16})/i',
                '/(?:tel(?:ephone)?|mobile|mob\.?|phone)\s*[:\-]?\s*\n\s*(\+?[\d\s\-\(\)]{7,16})/im',
                '/\b(\+?973[\s\-]?\d{4}[\s\-]?\d{4})\b/',
            ]),

            'email' => $this->matchPattern($text, [
                '/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/',
            ]),
        ];
    }

    /**
     * Extract the insured / owner name.
     * Requires a preceding label keyword so we never accidentally return
     * document headers like "KINGDOM OF BAHRAIN".
     */
    private function extractName(string $text): ?string
    {
        $patterns = [
            // Standard insurance labels
            '/(?:insured\s*name|name\s*of\s*insured|name\s*of\s*owner|owner\s*name|client\s*name|customer\s*name|policyholder)\s*[:\-]?\s*([A-Z][A-Za-z\s\.\-\']{2,60})/i',
            // Bahrain vehicle registration: "Owner" field
            '/\bowner\b\s*[:\-]?\s*([A-Z][A-Za-z\s\.\-\']{2,60})/i',
            // Generic "Name:" label
            '/(?<!\w)name\s*[:\-]\s*([A-Z][A-Za-z\s\.\-\']{2,60})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1]);
                // Strip any trailing noise (numbers, colons, common suffixes)
                $value = preg_replace('/\s*[\d:]+.*$/', '', $value);
                $value = trim($value);

                if (strlen($value) < 3) continue;

                // Reject if it matches a known document header
                $lower = strtolower($value);
                foreach (self::NAME_BLOCKLIST as $blocked) {
                    if (str_contains($lower, $blocked)) continue 2;
                }

                return $value;
            }
        }
        return null;
    }

    /**
     * Try each pattern in order; return the first captured group that matches.
     */
    private function matchPattern(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1] ?? '');
                if (strlen($value) > 0) {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * Like matchPattern() but normalises the matched date to DD/MM/YYYY.
     * Handles DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY, and strtotime-parseable strings.
     */
    private function extractDate(string $text, array $patterns): ?string
    {
        $raw = $this->matchPattern($text, $patterns);
        if (! $raw) return null;

        // Already DD/MM/YYYY or DD-MM-YYYY → normalise to slashes
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $raw, $m)) {
            return sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]);
        }

        // Try strtotime for written formats (e.g. "01 Jan 2025")
        $ts = @strtotime($raw);
        if ($ts && $ts > 0) {
            return date('d/m/Y', $ts);
        }

        return $raw;
    }
}