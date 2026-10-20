<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Proof of a quality incident.
 *
 * `pbx_signaling_events` is the live stream and is pruned by the signaling
 * retention setting. When Greg decides a call is poor, the evidence that led
 * to that decision is copied here: the sampled RTCP counters (time, peer,
 * SSRC, fraction lost, cumulative loss, jitter), the thresholds that were
 * applied, the trunk match, and the action he took. That is the trail a VoIP
 * engineer writes on a ticket — what was measured, where, when, and what was
 * changed — and it survives the raw rows being pruned.
 *
 * `evidence_json` is a bounded document (a handful of samples, not the whole
 * call); `dedupe_key` is one incident per call-id/window/action, so the
 * five-minute cron does not write the same proof every time.
 */
final class CreateQualityEvidence extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_quality_evidence')) {
            return;
        }
        $this->table('pbx_quality_evidence', [
            'id' => true,
            'primary_key' => 'id',
            'collation' => 'utf8mb4_general_ci',
            'encoding' => 'utf8mb4',
        ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('call_id', 'string', ['limit' => 128, 'default' => '', 'null' => false])
            ->addColumn('trunk_name', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('peer', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('level', 'string', ['limit' => 8, 'default' => 'poor', 'null' => false])
            ->addColumn('loss_pct', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => 0, 'null' => false])
            ->addColumn('jitter', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('reports', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('first_ts', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_ts', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('evidence_json', 'text', ['null' => true, 'default' => null])
            ->addColumn('action', 'string', ['limit' => 32, 'default' => 'none', 'null' => false])
            ->addColumn('action_detail', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('source', 'string', ['limit' => 16, 'default' => 'cron', 'null' => false])
            ->addColumn('dedupe_key', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addIndex(['created_at'])
            ->addIndex(['call_id'])
            ->addIndex(['dedupe_key'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_quality_evidence')) {
            $this->table('pbx_quality_evidence')->drop()->save();
        }
    }
}
