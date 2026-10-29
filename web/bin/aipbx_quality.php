#!/usr/bin/env php
<?php
/**
 * Call quality cron — every five minutes.
 *
 * Reads the RTCP counters the capture daemon stored, turns sustained packet
 * loss into a Greg notice, and makes the one safe automatic change: a lossy
 * trunk moves to the end of an outbound route's chain, for new calls only.
 * It never touches a live call and never edits a phone.
 *
 * Silent unless something happened; `--verbose` prints the decision as JSON
 * (used by tests and by an administrator running it by hand). A missing
 * migration, a database hiccup or a disabled Greg all end with exit 0: this
 * job must not page anyone because it could not look.
 *
 *   aipbx_quality.php                 one pass (cron, every five minutes)
 *   aipbx_quality.php --verbose       print the decision as JSON
 *   aipbx_quality.php --report[=24]   the stored incidents, with evidence ids
 *   aipbx_quality.php --evidence=12   the full trail of one incident
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/services/CallQualityEvidenceService.php';

$verbose = false;
$evidenceId = 0;
$reportHours = 0;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--verbose') {
        $verbose = true;
    } elseif (preg_match('/^--evidence=(\d+)$/', (string) $arg, $m)) {
        $evidenceId = (int) $m[1];
    } elseif (preg_match('/^--report(?:=(\d+))?$/', (string) $arg, $m)) {
        $reportHours = max(1, min(8760, (int) ($m[1] ?? 24)));
    }
}
if ($reportHours > 0) {
    fwrite(STDOUT, CallQualityEvidenceService::report($reportHours) . "\n");
    exit(0);
}
if ($evidenceId > 0) {
    $trail = CallQualityEvidenceService::evidenceTrail($evidenceId);
    if ($trail === '') {
        fwrite(STDERR, 'No quality evidence #' . $evidenceId . ".\n");
        exit(1);
    }
    fwrite(STDOUT, $trail . "\n");
    exit(0);
}

try {
    $result = CallQualityEvidenceService::tick();
} catch (\Throwable $e) {
    // The service already catches its own failures; this is the last fence so
    // cron never mails a stack trace.
    $result = ['error' => mb_substr($e->getMessage(), 0, 200)];
}
if ($verbose) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
exit(0);
