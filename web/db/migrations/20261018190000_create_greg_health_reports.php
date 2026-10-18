<?php

/**
 * Health checks Greg ran with a user: the answers the person gave, the state
 * the server reported (health_checks) and the Sipfront call test, frozen at
 * that moment. A report is a record of what was true when someone complained,
 * so it is never recomputed.
 */

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class CreateGregHealthReports extends AbstractMigration
{
    public function up(): void
    {
        $this->table('greg_health_reports', ['collation' => 'utf8mb4_general_ci', 'encoding' => 'utf8mb4'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('username', 'string', ['limit' => 50, 'default' => '', 'null' => false])
            ->addColumn('verdict', 'string', ['limit' => 8, 'default' => 'ok', 'null' => false])
            ->addColumn('answers', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('checks', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('sipfront', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('summary', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addIndex(['created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('greg_health_reports')->drop()->save();
    }
}
