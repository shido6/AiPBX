<?php

/**
 * AI → VoIP Engineer: the notices Greg shows in the top-bar panel.
 *
 * One row per informing event, deduplicated by dedupe_key inside a 6-hour
 * window (the "do not annoy" rule), with read_at for the panel badge and
 * emailed_at recording whether the e-mail channel actually fired — the
 * page shows both, so quiet hours and dedupe are observable, not hoped for.
 */

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class CreateGregNotices extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_greg_notices', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('severity', 'string', ['limit' => 8, 'default' => 'info', 'null' => false])
            ->addColumn('title', 'string', ['limit' => 150, 'default' => '', 'null' => false])
            ->addColumn('body', 'text', ['limit' => MysqlAdapter::TEXT_REGULAR, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'greg', 'null' => false])
            ->addColumn('dedupe_key', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('emailed_at', 'datetime', ['null' => true])
            ->addIndex(['dedupe_key', 'created_at'])
            ->addIndex(['created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('pbx_greg_notices')->drop()->save();
    }
}
