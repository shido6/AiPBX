#!/usr/bin/env php
<?php
/**
 * Greg's watch — AI → VoIP Engineer.
 *
 * Runs the portal's own health checks (the same ones Admin → Health checks
 * runs, without sending their e-mails) and turns every genuine problem into a
 * Greg notice: in the avatar panel for certain, by mail for warn/critical
 * outside quiet hours, deduplicated per check for six hours so a repeating
 * fault informs once instead of nagging.
 *
 * "OFF" is not a fault: it means the administrator switched that part off
 * (no trunk configured, network services disabled). INFO is informational.
 * Neither ever becomes a notice — Greg informs, he does not annoy.
 *
 *   greg_watch.php            one pass (cron, hourly)
 *   greg_watch.php --verbose  print what happened, for a human or a test
 *
 * Silent and cheap when Greg is disabled: the administrator turns him on
 * in AI → AI VoIP Engineer.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/services/GregService.php';

$verbose = in_array('--verbose', $argv ?? [], true);
$say = function (string $line) use ($verbose): void {
    if ($verbose) {
        fwrite(STDOUT, $line . "\n");
    }
};

if (!GregService::enabled()) {
    $say('Greg is disabled — nothing to watch.');
    exit(0);
}

// The checks print one line each: "<STATUS> <check> <human sentence>".
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/aipbx_health.php') . ' --all --no-mail 2>&1';
$lines = [];
exec($cmd, $lines);

$severity = ['FAIL' => 'critical', 'WARN' => 'warn'];
$made = 0;
foreach ($lines as $line) {
    if (!preg_match('/^\s*(OK|WARN|FAIL|OFF|INFO|SKIP)\s+(\S+)\s*(.*)$/i', (string) $line, $m)) {
        continue;
    }
    $status = strtoupper($m[1]);
    if (!isset($severity[$status])) {
        continue; // OK, OFF, INFO, SKIP: no reason to interrupt anyone
    }
    $check = $m[2];
    $text = trim($m[3]);
    $res = GregService::notify(
        $severity[$status],
        ucfirst($check) . ' — ' . $status,
        $text !== '' ? $text : t('ai_voip_engineer.sev_' . $severity[$status]),
        'health:' . $check
    );
    if (isset($res['id'])) {
        $made++;
        $say($status . ' ' . $check . ' → notice #' . $res['id']);
    } else {
        $say($status . ' ' . $check . ' → ' . (isset($res['deduped']) ? 'already told (6 h window)' : 'skipped'));
    }
}

$say('Done: ' . $made . ' new notice(s).');
exit(0);
