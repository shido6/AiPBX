<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * What Greg handed to opencode and what came back.
 *
 * opencode is used for two jobs: Greg's chat answer and the workshop proposal.
 * When its model is not local, the prompt and the answer leave the server and
 * must be auditable. One row per attempt records the exact prompt, the final
 * answer (or the honest error), model/provider, duration and who asked.
 *
 * Payloads are encrypted with SecretBox (AIPBX_SETTINGS_KEY / the local key
 * file), so a database dump alone does not reveal what a customer told Greg.
 * The row keeps sizes and hashes for the interface; the bytes stay bounded by
 * the service. Local providers are not recorded.
 */
final class CreateOpencodeExchanges extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('pbx_opencode_exchanges')) {
            return;
        }
        $this->table('pbx_opencode_exchanges', [
            'id' => true,
            'primary_key' => 'id',
            'collation' => 'utf8mb4_general_ci',
            'encoding' => 'utf8mb4',
        ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('purpose', 'string', ['limit' => 20, 'default' => 'chat', 'null' => false])
            ->addColumn('provider', 'string', ['limit' => 40, 'default' => '', 'null' => false])
            ->addColumn('model', 'string', ['limit' => 120, 'default' => '', 'null' => false])
            ->addColumn('status', 'string', ['limit' => 10, 'default' => 'ok', 'null' => false])
            ->addColumn('error', 'string', ['limit' => 500, 'default' => '', 'null' => false])
            ->addColumn('duration_ms', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('request_bytes', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('response_bytes', 'integer', ['signed' => false, 'default' => 0, 'null' => false])
            ->addColumn('request_sha256', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('response_sha256', 'string', ['limit' => 64, 'default' => '', 'null' => false])
            ->addColumn('request_enc', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('response_enc', 'text', ['limit' => MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true, 'default' => null])
            ->addColumn('user_name', 'string', ['limit' => 120, 'default' => '', 'null' => false])
            ->addIndex(['created_at'])
            ->addIndex(['model'])
            ->addIndex(['status'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('pbx_opencode_exchanges')) {
            $this->table('pbx_opencode_exchanges')->drop()->save();
        }
    }
}
