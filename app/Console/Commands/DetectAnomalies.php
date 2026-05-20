<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SecurityEventService;

/**
 * DetectAnomalies
 *
 * Laravel Artisan command that evaluates six anomaly rules against
 * the security_events Firestore collection. Run on a schedule via Kernel.php.
 *
 * Usage:   php artisan siem:detect
 * Schedule: $schedule->command('siem:detect')->everyMinute();
 *
 * Rules
 * ─────
 *  1. email_burst              — >10 emails from same user in 60 min   → CRITICAL
 *  2. unusual_send_time        — email sent between 23:00 and 05:00     → HIGH
 *  3. repeated_file_rejections — >5 rejections from same user in 30 min → HIGH
 *  4. admin_config_tampering   — >3 admin changes in 15 min             → HIGH
 *  5. zoho_token_failure_spike — ≥3 Zoho auth failures in 30 min        → MEDIUM
 *  6. large_file_upload        — single upload > 10 MB                  → MEDIUM
 */
class DetectAnomalies extends Command
{
    protected $signature   = 'siem:detect';
    protected $description = 'Run SIEM anomaly detection rules against security_events';

    public function __construct(private SecurityEventService $siem)
    {
        parent::__construct();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        $this->line('');
        $this->info('🛡  SIEM anomaly detection — ' . now()->toDateTimeString());

        $fired = 0;
        $fired += $this->ruleEmailBurst();
        $fired += $this->ruleUnusualSendTime();
        $fired += $this->ruleRepeatedFileRejections();
        $fired += $this->ruleAdminConfigTampering();
        $fired += $this->ruleZohoTokenFailureSpike();
        $fired += $this->ruleLargeFileUpload();

        $this->line('');
        $this->info("Detection complete. {$fired} anomaly alert(s) generated.");
        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 1 — Email Burst
    // Threshold: >10 emails from the same user within 10 minutes → CRITICAL
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleEmailBurst(): int
    {
        
        $events = $this->siem->getRecentEvents('EMAIL_SENT', 10);
        $byUser = [];

        foreach ($events as $e) {
            $uid = $e['userId'] ?? 'unknown';
            $byUser[$uid] = ($byUser[$uid] ?? 0) + 1;
        }

        $fired = 0;
        foreach ($byUser as $userId => $count) {
            if ($count <= 10) continue;
            if ($this->siem->openAlertExistsForRule('email_burst')) continue;

            $this->siem->log('ANOMALY_DETECTED', 'system', [
                'rule'    => 'email_burst',
                'userId'  => $userId,
                'count'   => $count,
                'window'  => '60 minutes',
                'message' => "{$count} emails sent in 60 minutes",
            ], 'critical', 'email_burst');

            $this->siem->createAlert(
                'email_burst',
                "User '{$userId}' sent {$count} emails in the last 60 minutes (threshold: 10)",
                'critical',
                $userId,
                ['count' => $count, 'window' => '60 min']
            );

            $this->warn("  [CRITICAL] Email burst: {$userId} sent {$count} emails");
            $fired++;
        }
        return $fired;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 2 — Unusual Send Time
    // Threshold: email sent between 23:00 and 05:00 → HIGH
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleUnusualSendTime(): int
    {
        $events = $this->siem->getRecentEvents('EMAIL_SENT', 15);
        $fired  = 0;

        foreach ($events as $event) {
            $ts = $event['timestamp'] ?? null;
            if (!$ts) continue;

            // kreait returns a Google\Cloud\Core\Timestamp
            $dt = ($ts instanceof \Google\Cloud\Core\Timestamp)
                ? $ts->get()
                : new \DateTimeImmutable((string) $ts);

            $hour = (int) $dt->format('H');
            if ($hour < 23 && $hour >= 5) continue;

            $userId  = $event['userId'] ?? 'unknown';
            $timeStr = $dt->format('H:i');

            $this->siem->log('ANOMALY_DETECTED', 'system', [
                'rule'    => 'unusual_send_time',
                'userId'  => $userId,
                'time'    => $timeStr,
                'message' => "Email sent at {$timeStr} — outside business hours",
            ], 'high', 'unusual_send_time');

            $this->siem->createAlert(
                'unusual_send_time',
                "Email sent at {$timeStr} by '{$userId}' — outside normal business hours (23:00–05:00)",
                'high',
                $userId,
                ['time' => $timeStr]
            );

            $this->warn("  [HIGH] Unusual send time: {$userId} at {$timeStr}");
            $fired++;
        }
        return $fired;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 3 — Repeated File Rejections
    // Threshold: >5 rejections from same user in 30 min → HIGH
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleRepeatedFileRejections(): int
    {
        $events = $this->siem->getRecentEvents('FILE_REJECTED', 30);
        $byUser = [];

        foreach ($events as $e) {
            $uid = $e['userId'] ?? 'unknown';
            $byUser[$uid] = ($byUser[$uid] ?? 0) + 1;
        }

        $fired = 0;
        foreach ($byUser as $userId => $count) {
            if ($count <= 5) continue;
            if ($this->siem->openAlertExistsForRule('repeated_file_rejections')) continue;

            $this->siem->log('ANOMALY_DETECTED', 'system', [
                'rule'    => 'repeated_file_rejections',
                'userId'  => $userId,
                'count'   => $count,
                'message' => "{$count} file rejections in 30 minutes",
            ], 'high', 'repeated_file_rejections');

            $this->siem->createAlert(
                'repeated_file_rejections',
                "User '{$userId}' had {$count} file rejections in 30 minutes — possible malicious upload attempts",
                'high',
                $userId,
                ['count' => $count, 'window' => '30 min']
            );

            $this->warn("  [HIGH] Repeated file rejections: {$userId} ×{$count}");
            $fired++;
        }
        return $fired;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 4 — Admin Config Tampering
    // Threshold: >3 admin config changes in 15 min → HIGH
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleAdminConfigTampering(): int
    {
        $events = $this->siem->getRecentEvents('ADMIN_CONFIG_CHANGED', 15);
        $count  = count($events);

        if ($count <= 3) return 0;
        if ($this->siem->openAlertExistsForRule('admin_config_tampering')) return 0;

        $userId = $events[0]['userId'] ?? 'unknown';

        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'admin_config_tampering',
            'count'   => $count,
            'message' => "{$count} admin config changes in 15 minutes",
        ], 'high', 'admin_config_tampering');

        $this->siem->createAlert(
            'admin_config_tampering',
            "{$count} admin configuration changes detected in 15 minutes — possible unauthorised tampering",
            'high',
            $userId,
            ['count' => $count, 'window' => '15 min']
        );

        $this->warn("  [HIGH] Admin config tampering: {$count} changes in 15 min");
        return 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 5 — Zoho Token Failure Spike
    // Threshold: ≥3 Zoho auth failures in 30 min → MEDIUM
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleZohoTokenFailureSpike(): int
    {
        $events = $this->siem->getRecentEvents('ZOHO_TOKEN_FAILURE', 30);
        $count  = count($events);

        if ($count < 3) return 0;
        if ($this->siem->openAlertExistsForRule('zoho_token_failure_spike')) return 0;

        $this->siem->log('ANOMALY_DETECTED', 'system', [
            'rule'    => 'zoho_token_failure_spike',
            'count'   => $count,
            'message' => "{$count} consecutive Zoho auth failures",
        ], 'medium', 'zoho_token_failure_spike');

        $this->siem->createAlert(
            'zoho_token_failure_spike',
            "{$count} Zoho authentication failures in 30 minutes — token may be expired or revoked",
            'medium',
            'system',
            ['count' => $count, 'window' => '30 min']
        );

        $this->warn("  [MEDIUM] Zoho token failure spike: {$count} failures");
        return 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RULE 6 — Abnormally Large File Upload
    // Threshold: single upload > 10 MB → MEDIUM
    // ─────────────────────────────────────────────────────────────────────────
    private function ruleLargeFileUpload(): int
    {
        $events = $this->siem->getRecentEvents('FILE_UPLOAD', 60);
        $fired  = 0;

        foreach ($events as $event) {
            $sizeBytes = $event['metadata']['sizeBytes'] ?? 0;
            $sizeMB    = round($sizeBytes / 1024 / 1024, 2);

            if ($sizeMB <= 10) continue;

            $userId = $event['userId'] ?? 'unknown';

            $this->siem->log('ANOMALY_DETECTED', 'system', [
                'rule'     => 'large_file_upload',
                'userId'   => $userId,
                'sizeMB'   => $sizeMB,
                'message'  => "Upload of {$sizeMB} MB detected",
            ], 'medium', 'large_file_upload');

            $this->siem->createAlert(
                'large_file_upload',
                "Abnormally large file ({$sizeMB} MB) uploaded by '{$userId}' — threshold: 10 MB",
                'medium',
                $userId,
                ['sizeMB' => $sizeMB]
            );

            $this->warn("  [MEDIUM] Large file upload: {$userId} uploaded {$sizeMB} MB");
            $fired++;
        }
        return $fired;
    }
}
