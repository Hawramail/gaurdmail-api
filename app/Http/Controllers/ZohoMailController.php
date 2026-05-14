<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class ZohoMailController extends Controller
{
    // ─────────────────────────────────────────────
    // GET ZOHO ACCOUNT ID + MAILBOX ADDRESS
    // POST /api/zoho/accounts
    // ─────────────────────────────────────────────
    public function getAccounts(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $token = $request->input('token');

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
        ])->get('https://mail.zoho.com/api/accounts');

        if ($response->failed()) {
            Log::error('Zoho getAccounts failed', ['body' => $response->body()]);
            return response()->json(['error' => 'Failed to fetch Zoho accounts'], 500);
        }

        $data = $response->json();
        $account = $data['data'][0] ?? null;

        if (!$account) {
            return response()->json(['error' => 'No Zoho account found'], 404);
        }

        return response()->json([
            'accountId'      => $account['accountId'],
            'mailboxAddress' => $account['mailboxAddress'] ?? $account['emailAddress'] ?? null,
        ]);
    }

    // ─────────────────────────────────────────────
    // SEND EMAIL WITH MERGED ATTACHMENTS
    // POST /api/zoho/sendEmailwAttachments
    //
    // Expected multipart/form-data fields:
    //   token        string   — Zoho OAuth access token
    //   accountId    string   — from getAccounts
    //   fromAddress  string   — sender mailbox
    //   toAddress    string   — comma-separated TO
    //   ccAddress    string   — comma-separated CC (optional)
    //   subject      string
    //   htmlBody     string   — rendered HTML template
    //   files[]      file[]   — uploaded attachments (PDF or images only, max 25 MB each)
    // ─────────────────────────────────────────────
    public function sendEmailwAttachments(Request $request)
    {
        // 1. Validate inputs
        $request->validate([
            'token'          => 'required|string',
            'accountId'      => 'required|string',
            'fromAddress'    => 'required|email',
            'toAddress'      => 'required|string',
            'subject'        => 'required|string',
            'htmlBody'       => 'required|string',
            'attachmentMode' => 'nullable|string|in:merge,separate',
            'files'          => 'nullable|array',
            'files.*'        => [
                'file',
                'max:25600',
                'mimetypes:application/pdf,image/jpeg,image/png,image/gif,image/bmp,image/tiff,image/webp',
            ],
        ]);

        $token          = $request->input('token');
        $accountId      = $request->input('accountId');
        $attachmentMode = $request->input('attachmentMode', 'separate');

        // 2. Handle attachments
        // In merge mode: all files (PDFs + images) are merged into one PDF via FPDI/TCPDF.
        // In separate mode: everything uploads as-is.
        $attachments = [];
        if ($request->hasFile('files')) {
            $files = $request->file('files');

            if ($attachmentMode === 'merge' && count($files) > 0) {
                try {
                    $pdfContent    = $this->mergeFilesToPdf($files);
                    $attachments[] = $this->uploadContentToZoho($token, $accountId, $pdfContent, 'attachments_merged.pdf');
                } catch (\Throwable $e) {
                    Log::error('PDF merge/upload failed', ['error' => $e->getMessage()]);
                    return response()->json(['error' => 'Merge failed: ' . $e->getMessage()], 500);
                }
            } else {
                foreach ($files as $file) {
                    try {
                        $attachments[] = $this->uploadFileToZoho($token, $accountId, $file);
                    } catch (\Throwable $e) {
                        Log::error('Attachment upload failed', ['error' => $e->getMessage()]);
                        return response()->json(['error' => 'Attachment upload failed: ' . $e->getMessage()], 500);
                    }
                }
            }
        }

        // 3. Send the email via Zoho API
        $payload = [
            'fromAddress' => $request->input('fromAddress'),
            'toAddress'   => $request->input('toAddress'),
            'ccAddress'   => $request->input('ccAddress', ''),
            'subject'     => $request->input('subject'),
            'mailFormat'  => 'html',
            'content'     => $request->input('htmlBody'),
        ];

        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $sendResponse = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/json',
        ])->post("https://mail.zoho.com/api/accounts/{$accountId}/messages", $payload);

        if ($sendResponse->failed()) {
            Log::error('Zoho send email failed', [
                'status' => $sendResponse->status(),
                'body'   => $sendResponse->body(),
            ]);
            return response()->json(['error' => 'Zoho send failed', 'details' => $sendResponse->json()], 500);
        }

        return response()->json([
            'success'       => true,
            'message'       => 'Email sent successfully',
            'zohoMessageId' => $sendResponse->json('data.messageId'),
            'response'      => $sendResponse->json(),
        ]);
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Merge PDF files into one PDF (PDF inputs only)
    // Non-PDF files are uploaded separately in sendEmailwAttachments.
    // ─────────────────────────────────────────────
    private function mergeFilesToPdf(array $files): string
    {
        // Anonymous subclass so TCPDF's Error() throws instead of calling die(),
        // which would kill the process and produce an uncatchable "Failed to fetch".
        $pdf = new class extends \setasign\Fpdi\Tcpdf\Fpdi {
            public function Error($msg)
            {
                throw new \RuntimeException('TCPDF: ' . $msg);
            }
        };
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pagesAdded = 0;
        $tempFiles  = [];

        foreach ($files as $file) {
            $ext      = strtolower($file->getClientOriginalExtension());
            $mime     = $file->getMimeType();
            $origName = $file->getClientOriginalName();
            // Use getPathname() — confirmed working for reading content on Windows
            $content  = file_get_contents($file->getPathname());

            if ($ext === 'pdf' || $mime === 'application/pdf') {
                $tmp = tempnam(sys_get_temp_dir(), 'mg_src_') . '.pdf';
                file_put_contents($tmp, $content);
                $tempFiles[] = $tmp;

                $pageCount = $pdf->setSourceFile($tmp);
                if ($pageCount === 0) {
                    throw new \Exception("FPDI could not read any pages from: {$origName}");
                }
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl  = $pdf->importPage($p);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                    $pagesAdded++;
                }
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) || str_starts_with($mime, 'image/')) {
                // Always convert to JPEG so TCPDF never sees alpha channels (which require Imagick).
                $tmp = tempnam(sys_get_temp_dir(), 'mg_jpg_') . '.jpg';
                $tempFiles[] = $tmp;

                $gdImg = imagecreatefromstring($content);
                if (!$gdImg) {
                    throw new \Exception("GD could not decode image: {$origName}");
                }
                $w = imagesx($gdImg);
                $h = imagesy($gdImg);
                $flat = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefill($flat, 0, 0, $white);
                imagecopy($flat, $gdImg, 0, 0, 0, 0, $w, $h);
                imagejpeg($flat, $tmp, 95);
                imagedestroy($gdImg);
                imagedestroy($flat);

                $pdf->AddPage('P', [210, 297]);
                $imgSize = getimagesize($tmp);
                if (!$imgSize) {
                    throw new \Exception("Could not read JPEG dimensions after conversion: {$origName}");
                }
                [$imgW, $imgH] = $imgSize;
                $ratio = min(190 / $imgW, 277 / $imgH);
                $dispW = $imgW * $ratio; $dispH = $imgH * $ratio;
                $pdf->Image($tmp, (210 - $dispW) / 2, (297 - $dispH) / 2, $dispW, $dispH, 'JPEG');
                $pagesAdded++;
            } else {
                $pdf->AddPage('P', [210, 297]);
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->SetTextColor(60, 60, 60);
                $pdf->SetY(130);
                $pdf->Cell(0, 10, 'Attachment: ' . $origName, 0, 1, 'C');
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(0, 8, 'File type: ' . strtoupper($ext) . ' — preview not available', 0, 1, 'C');
                $pagesAdded++;
            }
        }

        foreach ($tempFiles as $f) { @unlink($f); }

        if ($pagesAdded === 0) {
            throw new \Exception('No pages were added — check uploaded file types');
        }

        // ob_start guards against TCPDF builds that echo instead of returning with 'S'
        ob_start();
        $pdfContent = $pdf->Output('merged.pdf', 'S');
        $echoed     = ob_get_clean();

        if (empty($pdfContent) && !empty($echoed)) {
            $pdfContent = $echoed;
        }

        if (empty($pdfContent)) {
            throw new \Exception('PDF generation produced empty output after ' . $pagesAdded . ' page(s)');
        }

        return $pdfContent;
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Upload raw content string to Zoho, return attachment array
    // ─────────────────────────────────────────────
    private function uploadContentToZoho(string $token, string $accountId, string $content, string $filename): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/octet-stream',
        ])->withBody($content, 'application/octet-stream')
          ->post("https://mail.zoho.com/api/accounts/{$accountId}/messages/attachments?fileName=" . urlencode($filename));

        if ($response->failed()) {
            throw new \Exception('Zoho upload failed: ' . $response->body());
        }

        $data = $response->json();
        $info = $data['data'] ?? null;

        if (!$info || empty($info['attachmentPath'])) {
            throw new \Exception('No attachmentPath returned: ' . json_encode($data));
        }

        return [
            'attachmentPath' => $info['attachmentPath'],
            'attachmentName' => $info['attachmentName'] ?? $filename,
            'storeName'      => $info['storeName']      ?? '',
        ];
    }

    // ─────────────────────────────────────────────
    // PRIVATE: Upload a single file to Zoho, return attachmentId
    // ─────────────────────────────────────────────
    private function uploadFileToZoho(string $token, string $accountId, $file): array
    {
        $filename  = $file->getClientOriginalName();
        $filePath  = $file->getPathname(); // getPathname() is safer than getRealPath() on Windows

        if (!$filePath || !file_exists($filePath)) {
            throw new \Exception("Cannot locate uploaded file '{$filename}' at path: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new \Exception("Uploaded file '{$filename}' is 0 bytes at: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            throw new \Exception("Could not read '{$filename}' ({$fileSize} bytes) from: {$filePath}");
        }

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/octet-stream',
        ])->withBody($content, 'application/octet-stream')
          ->post("https://mail.zoho.com/api/accounts/{$accountId}/messages/attachments?fileName=" . urlencode($filename));

        if ($response->failed()) {
            throw new \Exception('Zoho upload failed: ' . $response->body());
        }

        $data = $response->json();
        $info = $data['data'] ?? null;

        if (!$info || empty($info['attachmentPath'])) {
            throw new \Exception('No attachmentPath returned: ' . json_encode($data));
        }

        return [
            'attachmentPath' => $info['attachmentPath'],
            'attachmentName' => $info['attachmentName'] ?? $filename,
            'storeName'      => $info['storeName']      ?? '',
        ];
    }
}