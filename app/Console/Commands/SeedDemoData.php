<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SecurityEventService;

/**
 * SeedDemoData
 *
 * Populates Firestore with realistic demo security events and alerts
 * for exhibition/demonstration purposes.
 *
 * Usage: php artisan siem:seed-demo
 *        php artisan siem:seed-demo --clear   (clears existing first)
 */
class SeedDemoData extends Command
{
    protected $signature   = 'siem:seed-demo {--clear : Clear existing events before seeding}';
    protected $description = 'Seed demo security events and alerts for SIEM demonstration';

    public function __construct(private SecurityEventService $siem)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('🌱  Seeding SIEM demo data...');

        // ── Normal activity ───────────────────────────────────────────────
        $this->line('  Seeding normal activity...');

        $templates = ['Issue New Policy', 'Change Issued Cover', 'Special Approval', 'Policy Transfer'];
        $companies = ['GIG Gulf', 'Solidarity', 'AXA Gulf', 'BFCB'];
        $users     = ['hawra@towergate.bh', 'staff1@towergate.bh', 'staff2@towergate.bh'];

        for ($i = 0; $i < 12; $i++) {
            $this->siem->log('EMAIL_SENT', $users[array_rand($users)], [
                'template' => $templates[array_rand($templates)],
                'company'  => $companies[array_rand($companies)],
                'message'  => 'Email sent successfully',
            ], 'low');
            usleep(80000);
        }

        for ($i = 0; $i < 6; $i++) {
            $this->siem->log('FILE_UPLOAD', $users[array_rand($users)], [
                'filename'  => "attachment_{$i}.pdf",
                'mimeType'  => 'application/pdf',
                'sizeBytes' => rand(200000, 3000000),
                'message'   => 'File uploaded successfully',
            ], 'low');
            usleep(80000);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->siem->log('ZOHO_AUTH_SUCCESS', $users[array_rand($users)], [
                'message' => 'Zoho OAuth token refreshed',
            ], 'low');
            usleep(80000);
        }

        // ── Anomalous activity ────────────────────────────────────────────
        $this->line('  Seeding anomalous events...');

        // 1. Email burst — CRITICAL
        for ($i = 0; $i < 11; $i++) {
            $this->siem->log('EMAIL_SENT', 'suspicious.user', [
                'template' => 'Issue New Policy',
                'company'  => 'GIG Gulf',
                'message'  => "Burst email #{$i}",
            ], 'low');
            usleep(50000);
        }
        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'email_burst',
            'userId'  => 'suspicious.user',
            'count'   => 11,
            'message' => '11 emails sent in 60 minutes',
        ], 'critical', 'email_burst');
        $this->siem->createAlert(
            'email_burst',
            "User 'suspicious.user' sent 11 emails in the last 60 minutes (threshold: 10)",
            'critical',
            'suspicious.user',
            ['count' => 11, 'window' => '60 min']
        );

        // 2. Repeated file rejections — HIGH
        for ($i = 0; $i < 6; $i++) {
            $this->siem->log('FILE_REJECTED', 'unknown.user', [
                'filename' => 'payload.exe',
                'mimeType' => 'application/x-msdownload',
                'reason'   => 'Invalid MIME type — executable blocked',
                'message'  => 'File rejected',
            ], 'medium');
            usleep(50000);
        }
        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'repeated_file_rejections',
            'userId'  => 'unknown.user',
            'count'   => 6,
            'message' => '6 file rejections in 30 minutes',
        ], 'high', 'repeated_file_rejections');
        $this->siem->createAlert(
            'repeated_file_rejections',
            "User 'unknown.user' had 6 file rejections in 30 minutes — possible malicious upload attempts",
            'high',
            'unknown.user',
            ['count' => 6, 'window' => '30 min']
        );

        // 3. Admin config tampering — HIGH
        for ($i = 0; $i < 4; $i++) {
            $this->siem->log('ADMIN_CONFIG_CHANGED', 'admin.user', [
                'field'   => 'TO email',
                'company' => $companies[array_rand($companies)],
                'message' => 'Recipient list modified',
            ], 'medium');
            usleep(50000);
        }
        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'admin_config_tampering',
            'count'   => 4,
            'message' => '4 admin config changes in 15 minutes',
        ], 'high', 'admin_config_tampering');
        $this->siem->createAlert(
            'admin_config_tampering',
            '4 admin configuration changes detected in 15 minutes — possible unauthorised tampering',
            'high',
            'admin.user',
            ['count' => 4, 'window' => '15 min']
        );

        // 4. Zoho token failure spike — MEDIUM
        for ($i = 0; $i < 3; $i++) {
            $this->siem->log('ZOHO_TOKEN_FAILURE', 'system', [
                'attempt' => $i + 1,
                'message' => 'Token refresh failed — 401 Unauthorized',
            ], 'medium');
            usleep(50000);
        }
        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'zoho_token_failure_spike',
            'count'   => 3,
            'message' => '3 consecutive Zoho auth failures',
        ], 'medium', 'zoho_token_failure_spike');
        $this->siem->createAlert(
            'zoho_token_failure_spike',
            '3 Zoho authentication failures in 30 minutes — token may be expired or revoked',
            'medium',
            'system',
            ['count' => 3, 'window' => '30 min']
        );

        // 5. Large file upload — MEDIUM
        $this->siem->log('FILE_UPLOAD', 'staff1@towergate.bh', [
            'filename'  => 'large_policy_pack.pdf',
            'mimeType'  => 'application/pdf',
            'sizeBytes' => 14_500_000,
            'message'   => 'Large file uploaded',
        ], 'medium');
        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'large_file_upload',
            'userId'  => 'staff1@towergate.bh',
            'sizeMB'  => 13.8,
            'message' => 'Upload of 13.8 MB detected',
        ], 'medium', 'large_file_upload');
        $this->siem->createAlert(
            'large_file_upload',
            "Abnormally large file (13.8 MB) uploaded by 'staff1@towergate.bh' — threshold: 10 MB",
            'medium',
            'staff1@towergate.bh',
            ['sizeMB' => 13.8]
        );

        $this->line('');
        $this->info('✅  Demo data seeded successfully!');
        $this->line('   Open the SIEM dashboard to see live events and alerts.');
        $this->line('   Run the simulation button for additional real-time events.');
        return Command::SUCCESS;
    }
}
