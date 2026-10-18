<?php
/** @var array $echo @var string $setup_steps @var array $runs @var ?string $runs_error
 *  @var ?array $last_run @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-wave-square u-primary"></i> <?php echo t('ai_echo.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelpBox('echoHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="echoHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_echo.title'); ?></h4>
        <?php echo t('ai_echo.help_body'); ?>
    </div>

    <div style="padding: 0 20px 20px;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <span class="badge <?php echo $echo['configured'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
                <i class="fas fa-link"></i>
                <?php echo $echo['configured'] ? t('ai_echo.keys_set') : t('ai_echo.not_configured'); ?>
            </span>
            <span class="badge <?php echo $echo['enabled'] ? 'badge-info' : 'badge-secondary'; ?> u-fs-11">
                <?php echo t($echo['enabled'] ? 'ai_voip_engineer.status_active' : 'ai_voip_engineer.status_disabled'); ?>
            </span>
            <?php if ($echo['public_key_masked'] !== ''): ?>
                <span class="u-muted u-fs-11"><?php echo t('ai_echo.public_key'); ?>: <code><?php echo $h($echo['public_key_masked']); ?></code></span>
            <?php endif; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">

            <label class="u-check-label" style="margin-bottom:14px;display:block;">
                <input type="checkbox" class="u-accent" name="echo_enabled" value="1" <?php echo $echo['enabled'] ? 'checked' : ''; ?>>
                <?php echo t('ai_echo.enable'); ?>
            </label>

            <div class="u-grid-2" style="gap:16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.public_key'); ?></label>
                    <input type="text" class="form-control" name="echo_public_key" autocomplete="off"
                           value="<?php echo $h($echo['public_key']); ?>" placeholder="pk_…">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.secret_key'); ?></label>
                    <input type="password" class="form-control" name="echo_secret" autocomplete="new-password"
                           value="" placeholder="<?php echo $h($echo['secret_set'] ? t('ai_tts.keep_secret') : 'sk_…'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.test_id'); ?></label>
                    <input type="text" class="form-control" name="echo_test_id" inputmode="numeric"
                           value="<?php echo $echo['test_id'] > 0 ? (int) $echo['test_id'] : ''; ?>" placeholder="1234">
                </div>
            </div>

            <div class="u-muted u-fs-11 u-mt-4" style="margin-bottom:12px;">
                <i class="fas fa-lock"></i> <?php echo t('ai_tts.providers_text'); ?>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="submit" name="save_echo" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
                <button type="submit" name="test_connection" value="1" class="btn btn-secondary" <?php echo $echo['configured'] ? '' : 'disabled'; ?>>
                    <i class="fas fa-plug-circle-check"></i> <?php echo t('ai_tts.btn_test'); ?>
                </button>
                <?php if ($echo['test_id'] > 0): ?>
                    <button type="submit" name="run_test" value="1" class="btn btn-secondary" <?php echo $echo['configured'] ? '' : 'disabled'; ?>>
                        <i class="fas fa-play"></i> <?php echo t('ai_echo.run_test'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <div class="u-muted u-fs-12 u-mt-14" style="border-top:1px solid var(--border-color);padding-top:12px;">
            <i class="fas fa-circle-info"></i> <?php echo $h($setup_steps); ?>
        </div>
        <div class="u-muted u-fs-12 u-mt-6">
            <i class="fas fa-shield-halved"></i> <?php echo t('ai_voip_engineer.audit_note'); ?>
        </div>

        <?php if ($echo['configured']): ?>
            <div class="u-strong u-fs-13" style="margin-top:16px;">
                <i class="fas fa-clock-rotate-left"></i> <?php echo t('ai_echo.recent_runs'); ?>
            </div>
            <?php if ($runs_error): ?>
                <div class="u-fs-12" style="color:var(--danger);margin-top:6px;"><?php echo $h($runs_error); ?></div>
            <?php elseif (!$runs): ?>
                <div class="u-muted u-fs-12" style="margin-top:6px;"><?php echo t('ai_echo.no_runs'); ?></div>
            <?php else: ?>
                <div class="table-responsive" style="margin-top:8px;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?php echo t('ai_echo.run_test'); ?></th>
                                <th><?php echo t('ai_echo.public_key'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($runs as $r): ?>
                            <?php
                            $sid = (string) ($r['session_id'] ?? $r['id'] ?? '');
                            $report = (string) ($r['report_url'] ?? '');
                            $okUrl = str_starts_with($report, 'https://');
                            ?>
                            <tr>
                                <td class="u-fs-12"><?php echo $h($r['test_name'] ?? $r['name'] ?? ('#' . ($r['test_id'] ?? '?'))); ?></td>
                                <td class="u-fs-12 u-muted"><?php echo $h($r['started_at'] ?? $r['created_at'] ?? ''); ?></td>
                                <td class="u-fs-12">
                                    <?php if (array_key_exists('is_failed', $r) && (int) $r['is_failed'] === 1): ?>
                                        <span class="badge badge-danger u-fs-10"><?php echo t('health.status_fail'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($okUrl): ?>
                                        <a class="u-fs-11" href="<?php echo $h($report); ?>" target="_blank" rel="noopener noreferrer"><?php echo $h($sid !== '' ? $sid : '—'); ?></a>
                                    <?php else: ?>
                                        <span class="u-muted"><?php echo $h($sid); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
