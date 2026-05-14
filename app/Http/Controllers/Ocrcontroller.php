<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrController extends Controller
{
    /**
     * Thin proxy: forward the uploaded file to OCR.space using the
     * server-side API key, then return OCR.space's JSON as-is so the
     * Vue frontend can parse it exactly as before.
     *
     * POST /api/ocr/extract
     * Body: multipart/form-data { file, OCREngine }
     */
    public function extract(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf,bmp,tiff|max:10240',
        ]);

        $file   = $request->file('file');
        $engine = $request->input('OCREngine', '1');
        $apiKey = env('OCR_SPACE_API_KEY', 'K82551922288957');

        try {
            $mimeType = $file->getMimeType() ?: 'image/jpeg';
            $base64   = base64_encode(file_get_contents($file->getPathname()));
            $dataUri  = "data:{$mimeType};base64,{$base64}";

            $response = Http::timeout(60)->post('https://api.ocr.space/parse/image', [
                'apikey'            => $apiKey,
                'base64Image'       => $dataUri,
                'language'          => 'eng',
                'isOverlayRequired' => 'false',
                'detectOrientation' => 'true',
                'scale'             => 'true',
                'isTable'           => 'false',
                'OCREngine'         => $engine,
            ]);

            $data = $response->json();

            Log::info('OCR.space response', [
                'engine'   => $engine,
                'exitCode' => $data['OCRExitCode']           ?? null,
                'errored'  => $data['IsErroredOnProcessing'] ?? null,
                'preview'  => isset($data['ParsedResults'][0])
                    ? substr($data['ParsedResults'][0]['ParsedText'] ?? '', 0, 300)
                    : null,
            ]);

            // Return OCR.space response unchanged — frontend parses ParsedResults as before
            return response()->json($data);

        } catch (\Exception $e) {
            Log::error('OCR proxy error: ' . $e->getMessage());

            // Match OCR.space error shape so frontend error handling still works
            return response()->json([
                'IsErroredOnProcessing' => true,
                'ErrorMessage'          => [$e->getMessage()],
                'ParsedResults'         => [],
            ], 502);
        }
    }
}
