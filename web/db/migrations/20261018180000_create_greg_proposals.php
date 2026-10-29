<?php

/**
 * Greg's proposals: what opencode suggests (a tool to add, a research plan)
 * before an administrator decides. Nothing here is installed automatically —
 * the row is the review queue, status is the decision, and the audit log
 * carries both events.
 */

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class CreateGregProposals extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_greg_proposals', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addColumn('kind', 'string', ['limit' => 16, 'default' => 'tool', 'null' => false])
            ->addColumn('task', 'string', ['limit' => 2000, 'null' => false])
            ->addColumn('output', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending', 'null' => false])
            ->addColumn('model', 'string', ['limit' => 120, 'default' => '', 'null' => false])
            ->addColumn('decided_at', 'datetime', ['null' => true])
            ->addColumn('decided_by', 'integer', ['null' => true])
            ->addIndex(['status', 'created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('pbx_greg_proposals')->drop()->save();
    }
}
