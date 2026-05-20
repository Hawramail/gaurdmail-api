<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

/**
 * ZohoMailController
 *
 * Handles all Zoho Mail API communication on behalf of the Vue frontend.
 * The frontend (useZoho.js) calls two endpoints in sequence when the user hits "Send":
 *
 *   Step 1 — GET /api/zoho/accounts        → getAccounts()
 *             Vue needs the accountId before it can send. Zoho requires you to say
 *             "send FROM this specific mailbox account" — you can't just pass an email
 *             address. getAccounts() fetches the account list from Zoho and returns
 *             the first account's ID and mailbox address back to Vue.
 *
 *   Step 2 — POST /api/zoho/sendEmailwAttachments → sendEmailwAttachments()
 *             Vue passes the token, accountId, recipients, subject, HTML body, and
 *             any uploaded files. This method handles attachment processing (merge or
 *             separate upload) and then fires the actual send via Zoho's API.
 *
 * Zoho's API requires attachments to be uploaded BEFORE the send request — you cannot
 * include raw file bytes in the send payload. Instead, you upload each file first,
 * get back an attachmentPath reference, and include that reference in the send call.
 * Both upload helpers (uploadContentToZoho, uploadFileToZoho) handle this pre-upload step.
 */
class ZohoMailController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: GET ZOHO ACCOUNT ID + MAILBOX ADDRESS
    // Route: POST /api/zoho/accounts
    //
    // Called by useZoho.js FIRST, before sendEmailwAttachments.
    // Vue needs the accountId to construct the correct Zoho API URL for sending.
    // Without it, Zoho rejects the send request — it doesn't accept just an email address.
    // ─────────────────────────────────────────────────────────────────────────
    public function getAccounts(Request $request)
    {
        // Require the OAuth token — this is the Zoho access token the user obtained
        // during the Zoho OAuth flow in the Vue app. Without it we can't call Zoho at all.
        $request->validate(['token' => 'required|string']);

        $token = $request->input('token');

        // Call Zoho's account list endpoint. Zoho uses its own Authorization header format:
        // "Zoho-oauthtoken <token>" — not the standard "Bearer <token>" format.
        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
        ])->get('https://mail.zoho.com/api/accounts');

        if ($response->failed()) {
            // Log the full Zoho error body so we can debug token expiry, scope issues, etc.
            Log::error('Zoho getAccounts failed', ['body' => $response->body()]);
            return response()->json(['error' => 'Failed to fetch Zoho accounts'], 500);
        }

        $data = $response->json();

        // Zoho returns an array of accounts under 'data'. We always use the first one.
        // Most users only have one Zoho mailbox account, so index 0 is the right choice.
        $account = $data['data'][0] ?? null;

        if (!$account) {
            return response()->json(['error' => 'No Zoho account found'], 404);
        }

        // Return only what Vue needs:
        //   accountId      — used in every subsequent Zoho API URL (e.g. /accounts/{accountId}/messages)
        //   mailboxAddress — displayed in the "From" field in the Vue UI
        // Zoho sometimes puts the address under 'mailboxAddress', sometimes 'emailAddress' — handle both.
        return response()->json([
            'accountId'      => $account['accountId'],
            'mailboxAddress' => $account['mailboxAddress'] ?? $account['emailAddress'] ?? null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC: SEND EMAIL WITH ATTACHMENTS
    // Route: POST /api/zoho/sendEmailwAttachments
    //
    // This is the main send method. Three phases:
    //   1. Validate all incoming fields and files server-side
    //   2. Process attachments (merge into one PDF, or upload each file separately)
    //   3. Fire the send request to Zoho with the email payload + attachment references
    //
    // Expected multipart/form-data fields:
    //   token          string  — Zoho OAuth access token
    //   accountId      string  — from getAccounts()
    //   fromAddress    string  — sender mailbox address (must belong to the account)
    //   toAddress      string  — comma-separated recipient list
    //   ccAddress      string  — comma-separated CC list (optional)
    //   subject        string
    //   htmlBody       string  — pre-rendered HTML email body from the Vue template engine
    //   attachmentMode string  — "merge" (all files → one PDF) or "separate" (each file as-is)
    //   files[]        file[]  — uploaded files (PDF or image only, max 25 MB each)
    // ─────────────────────────────────────────────────────────────────────────
    public function sendEmailwAttachments(Request $request)
    {
        // ── PHASE 1: Server-side validation ──────────────────────────────────
        // Even though Vue validates on the frontend, we re-validate here because:
        //   - API calls can bypass the frontend entirely
        //   - File MIME types and sizes must be enforced at the server level
        //   - 'max:25600' = 25,600 KB = 25 MB per file (Zoho's attachment limit)
        //   - 'mimetypes' ensures only PDFs and common image formats are accepted —
        //     other file types (Word, Excel, ZIP, etc.) are rejected here
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
                'max:25600',   // 25 MB per file
                'mimetypes:application/pdf,image/jpeg,image/png,image/gif,image/bmp,image/tiff,image/webp',
            ],
        ]);

        $token          = $request->input('token');
        $accountId      = $request->input('accountId');

        // Default to 'separate' if attachmentMode was not sent — safest fallback,
        // each file keeps its original format and name.
        $attachmentMode = $request->input('attachmentMode', 'separate');

        // ── PHASE 2: Attachment processing ───────────────────────────────────
        // Zoho's send API does NOT accept raw file bytes inline — you must upload
        // attachments first and get back an attachmentPath token, then include
        // that token in the send payload. This is a two-step process Zoho requires.
        //
        // Two modes controlled by 'attachmentMode':
        //
        //   MERGE MODE ("merge"):
        //     All uploaded files (PDFs + images) are merged into a single PDF by
        //     mergeFilesToPdf(). That PDF is then uploaded once to Zoho via
        //     uploadContentToZoho(). The recipient gets one clean attachment: "attachments_merged.pdf".
        //     Useful when sending many pages that should be read as a single document.
        //
        //   SEPARATE MODE ("separate"):
        //     Each file is uploaded to Zoho individually via uploadFileToZoho() and
        //     appears as its own attachment in the email. The recipient sees each file
        //     with its original filename.
        $attachments = [];
        if ($request->hasFile('files')) {
            $files = $request->file('files');

            if ($attachmentMode === 'merge' && count($files) > 0) {
                try {
                    // mergeFilesToPdf() returns a raw PDF binary string.
                    // uploadContentToZoho() takes that string, uploads it to Zoho,
                    // and returns the attachmentPath reference we need for the send payload.
                    $pdfContent    = $this->mergeFilesToPdf($files);
                    $attachments[] = $this->uploadContentToZoho($token, $accountId, $pdfContent, 'attachments_merged.pdf');
                } catch (\Throwable $e) {
                    Log::error('PDF merge/upload failed', ['error' => $e->getMessage()]);
                    return response()->json(['error' => 'Merge failed: ' . $e->getMessage()], 500);
                }
            } else {
                // Separate mode: loop through every uploaded file and upload each one.
                // If any single upload fails, we abort immediately — we don't want to
                // send the email with only a partial set of attachments.
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

        // ── PHASE 3: Send the email via Zoho API ─────────────────────────────
        // Build the send payload. 'mailFormat' => 'html' tells Zoho to render the
        // content as HTML, not plain text. Without this, HTML tags show up literally.
        $payload = [
            'fromAddress' => $request->input('fromAddress'),
            'toAddress'   => $request->input('toAddress'),
            'ccAddress'   => $request->input('ccAddress', ''),  // empty string = no CC
            'subject'     => $request->input('subject'),
            'mailFormat'  => 'html',
            'content'     => $request->input('htmlBody'),
        ];

        // Only add the 'attachments' key if we actually have files — Zoho rejects
        // an empty attachments array, so we omit the key entirely when there are none.
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        // Fire the send request. URL includes accountId because Zoho routes the message
        // through that specific mailbox — it's how Zoho knows which "From" account to use.
        $sendResponse = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $token,
            'Content-Type'  => 'application/json',
        ])->post("https://mail.zoho.com/api/accounts/{$accountId}/messages", $payload);

        if ($sendResponse->failed()) {
            Log::error('Zoho send email failed', [
                'status' => $sendResponse->status(),
                'body'   => $sendResponse->body(),
            ]);
            // Pass Zoho's full error JSON back to Vue so the UI can show a specific reason
            // (e.g. "Invalid fromAddress", "Token expired") rather than a generic message.
            return response()->json(['error' => 'Zoho send failed', 'details' => $sendResponse->json()], 500);
        }

        // Return the Zoho message ID to Vue. Vue saves this in the sent-emails log
        // so users can reference or track the message later.
        return response()->json([
            'success'       => true,
            'message'       => 'Email sent successfully',
            'zohoMessageId' => $sendResponse->json('data.messageId'),
            'response'      => $sendResponse->json(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Merge all uploaded files into a single PDF binary string
    //
    // Uses FPDI (on top of TCPDF) — a PHP library that can import existing PDF pages
    // and composite them into a new PDF document.
    //
    // Handles three file categories:
    //   1. PDFs     → pages imported directly into the merged document (layout preserved)
    //   2. Images   → converted to JPEG, placed centred on an A4 page
    //   3. Anything else → placeholder page with the filename and a "preview not available" note
    //
    // Returns the merged PDF as a raw binary string (not a file path).
    // The caller (sendEmailwAttachments) passes that string to uploadContentToZoho().
    // ─────────────────────────────────────────────────────────────────────────
    private function mergeFilesToPdf(array $files): string
    {
        // We use an anonymous subclass to override TCPDF's Error() method.
        // By default, TCPDF calls die() on fatal errors — that silently kills the PHP
        // process, causing the frontend to see a generic "Failed to fetch" network error
        // with no useful information. By throwing a RuntimeException instead, the error
        // propagates normally and gets caught by the try/catch in sendEmailwAttachments,
        // which then returns a proper JSON error response to Vue.
        $pdf = new class extends \setasign\Fpdi\Tcpdf\Fpdi {
            public function Error($msg)
            {
                throw new \RuntimeException('TCPDF: ' . $msg);
            }
        };

        // Disable auto page breaks — we control page dimensions explicitly for each file.
        // Disable header/footer — we want a clean document with no TCPDF watermarks.
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pagesAdded = 0;   // tracks how many pages ended up in the merged PDF
        $tempFiles  = [];  // paths of temp files we create — cleaned up at the end

        foreach ($files as $file) {
            $ext      = strtolower($file->getClientOriginalExtension());
            $mime     = $file->getMimeType();
            $origName = $file->getClientOriginalName();

            // getPathname() returns the server-side temp path where PHP stored the upload.
            // On Windows, getRealPath() can return false for temp files — getPathname() is reliable.
            $content  = file_get_contents($file->getPathname());

            // ── Case 1: PDF files ─────────────────────────────────────────────
            // FPDI needs a real file path to read a PDF — it cannot read from a string.
            // So we write the uploaded content to a new temp file, then point FPDI at it.
            if ($ext === 'pdf' || $mime === 'application/pdf') {
                $tmp = tempnam(sys_get_temp_dir(), 'mg_src_') . '.pdf';
                file_put_contents($tmp, $content);
                $tempFiles[] = $tmp;  // register for cleanup

                // setSourceFile() tells FPDI which PDF to read from and returns the page count.
                $pageCount = $pdf->setSourceFile($tmp);
                if ($pageCount === 0) {
                    throw new \Exception("FPDI could not read any pages from: {$origName}");
                }

                // Import every page from this PDF into the merged document.
                // We read the original page dimensions so the merged PDF preserves the
                // source layout — A4 portrait stays A4, landscape stays landscape, etc.
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl  = $pdf->importPage($p);             // import page as a reusable template
                    $size = $pdf->getTemplateSize($tpl);      // get original width/height in mm
                    // Detect orientation: if width > height the page is landscape
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);  // stamp the page content
                    $pagesAdded++;
                }

            // ── Case 2: Image files (JPG, PNG, GIF, WEBP, etc.) ──────────────
            // TCPDF can embed images, but PNG/GIF files with alpha transparency (semi-transparent
            // pixels) require the Imagick PHP extension — which is often not installed.
            // To avoid this dependency, we always convert images to JPEG first using GD
            // (which IS available by default). JPEG has no alpha channel, so TCPDF handles
            // it without Imagick. The conversion also flattens any transparency onto a white background.
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) || str_starts_with($mime, 'image/')) {
                $tmp = tempnam(sys_get_temp_dir(), 'mg_jpg_') . '.jpg';
                $tempFiles[] = $tmp;

                // imagecreatefromstring() auto-detects the image format (PNG, GIF, etc.)
                $gdImg = imagecreatefromstring($content);
                if (!$gdImg) {
                    throw new \Exception("GD could not decode image: {$origName}");
                }

                $w = imagesx($gdImg);
                $h = imagesy($gdImg);

                // Create a fresh true-colour canvas filled with white.
                // Compositing the original image onto this white canvas flattens any
                // alpha channel — transparent areas become white instead of black or garbled.
                $flat  = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefill($flat, 0, 0, $white);
                imagecopy($flat, $gdImg, 0, 0, 0, 0, $w, $h);  // paste original on top of white

                // Save as JPEG at quality 95 — high quality, no alpha, TCPDF-safe
                imagejpeg($flat, $tmp, 95);
                imagedestroy($gdImg);
                imagedestroy($flat);

                // Add an A4 page (210 × 297 mm) and centre the image on it.
                // We scale the image to fit within a 190 × 277 mm area (10 mm margin on each side)
                // while preserving aspect ratio — min() picks the limiting dimension.
                $pdf->AddPage('P', [210, 297]);
                $imgSize = getimagesize($tmp);
                if (!$imgSize) {
                    throw new \Exception("Could not read JPEG dimensions after conversion: {$origName}");
                }
                [$imgW, $imgH] = $imgSize;
                $ratio = min(190 / $imgW, 277 / $imgH);  // uniform scale factor to fit within margins
                $dispW = $imgW * $ratio;
                $dispH = $imgH * $ratio;
                // Centre horizontally: (210 - $dispW) / 2   Centre vertically: (297 - $dispH) / 2
                $pdf->Image($tmp, (210 - $dispW) / 2, (297 - $dispH) / 2, $dispW, $dispH, 'JPEG');
                $pagesAdded++;

            // ── Case 3: Unsupported file type ─────────────────────────────────
            // The validator already blocked most unsupported types, but this catches edge cases.
            // Rather than silently skipping the file, we add a placeholder page so the user
            // can see that the file was received but couldn't be rendered.
            } else {
                $pdf->AddPage('P', [210, 297]);
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->SetTextColor(60, 60, 60);
                $pdf->SetY(130);  // position text roughly in the vertical centre of the page
                $pdf->Cell(0, 10, 'Attachment: ' . $origName, 0, 1, 'C');
                $pdf->SetFont('helvetica', '', 10);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(0, 8, 'File type: ' . strtoupper($ext) . ' — preview not available', 0, 1, 'C');
                $pagesAdded++;
            }
        }

        // Clean up all temp files we created during this merge run.
        // Using @ to suppress errors in case a file was already deleted.
        foreach ($tempFiles as $f) { @unlink($f); }

        if ($pagesAdded === 0) {
            throw new \Exception('No pages were added — check uploaded file types');
        }

        // Output the finished PDF as a binary string using 'S' mode.
        // Some TCPDF builds echo the PDF bytes instead of returning them via 'S' —
        // ob_start() captures any such echo so we can still return the content correctly.
        ob_start();
        $pdfContent = $pdf->Output('merged.pdf', 'S');
        $echoed     = ob_get_clean();

        // If TCPDF returned nothing but echoed something, use the echoed bytes
        if (empty($pdfContent) && !empty($echoed)) {
            $pdfContent = $echoed;
        }

        if (empty($pdfContent)) {
            throw new \Exception('PDF generation produced empty output after ' . $pagesAdded . ' page(s)');
        }

        return $pdfContent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Upload a raw binary string to Zoho's attachment endpoint
    //
    // Used in MERGE mode: takes the PDF binary string produced by mergeFilesToPdf()
    // and uploads it to Zoho. Returns the attachmentPath token that Zoho needs
    // in the send payload to attach the file to the outgoing email.
    //
    // Why a separate method from uploadFileToZoho()?
    //   uploadFileToZoho() reads a file off disk.
    //   This method accepts content that's already in memory (the merged PDF string).
    //   Keeping them separate avoids writing the merged PDF to disk just to read it back.
    // ─────────────────────────────────────────────────────────────────────────
    private function uploadContentToZoho(string $token, string $accountId, string $content, string $filename): array
    {
        // Zoho's attachment upload endpoint accepts raw bytes with Content-Type: application/octet-stream.
        // The filename is passed as a query parameter — Zoho uses it as the display name in the email.
        // urlencode() ensures spaces and special characters in filenames don't break the URL.
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

        // Zoho must return 'attachmentPath' — without it we have no way to reference
        // this file in the send payload and the attachment would be silently lost.
        if (!$info || empty($info['attachmentPath'])) {
            throw new \Exception('No attachmentPath returned: ' . json_encode($data));
        }

        // Return the three fields Zoho requires in the send payload's 'attachments' array.
        // attachmentPath — the Zoho-internal reference to the uploaded file (acts like an ID)
        // attachmentName — the display name shown to the email recipient
        // storeName      — Zoho's internal storage bucket identifier (required by some Zoho regions)
        return [
            'attachmentPath' => $info['attachmentPath'],
            'attachmentName' => $info['attachmentName'] ?? $filename,
            'storeName'      => $info['storeName']      ?? '',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Upload a single uploaded file to Zoho's attachment endpoint
    //
    // Used in SEPARATE mode: reads the file from its server-side temp path,
    // sends the raw bytes to Zoho, and returns the attachmentPath token.
    // Each file in the loop gets its own call to this method.
    //
    // Why not pass the file object directly to Http::attach()?
    //   Laravel's Http::attach() uses multipart form encoding, but Zoho's attachment
    //   endpoint expects raw octet-stream bytes — not multipart. We read the file
    //   manually and send the bytes directly using withBody().
    // ─────────────────────────────────────────────────────────────────────────
    private function uploadFileToZoho(string $token, string $accountId, $file): array
    {
        $filename = $file->getClientOriginalName();

        // getPathname() returns the path where PHP's upload handler stored the temp file.
        // On Windows, getRealPath() can return false for temp files because the path
        // doesn't go through a real filesystem symlink — getPathname() always works.
        $filePath = $file->getPathname();

        // Guard: if PHP's temp file disappeared (e.g. cleared between validation and here)
        if (!$filePath || !file_exists($filePath)) {
            throw new \Exception("Cannot locate uploaded file '{$filename}' at path: {$filePath}");
        }

        // Guard: zero-byte file would produce an empty upload, which Zoho would reject
        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new \Exception("Uploaded file '{$filename}' is 0 bytes at: {$filePath}");
        }

        // Read the full file into memory. For files up to 25 MB (our enforced limit)
        // this is acceptable. file_get_contents returns false on failure, '' on empty read.
        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            throw new \Exception("Could not read '{$filename}' ({$fileSize} bytes) from: {$filePath}");
        }

        // Upload raw bytes to Zoho. Same approach as uploadContentToZoho() —
        // octet-stream body, filename in the query string.
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
