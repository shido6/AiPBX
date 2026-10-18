<?php
/** @var array $greg @var int $month_tokens @var int $unread @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$sources = [
    'bigpickle' => t('ai_voip_engineer.source_bigpickle'),
    'system'    => t('ai_voip_engineer.source_system'),
    'ling'      => t('ai_voip_engineer.source_ling'),
];
?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-user-astronaut u-primary"></i> <?php echo t('ai_voip_engineer.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelpBox('gregHelpBox')" title="<?php echo t('common.module_guide'); ?>"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="gregHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_voip_engineer.help_title'); ?></h4>
        <?php echo t('ai_voip_engineer.help_body'); ?>
    </div>

    <div style="padding: 0 20px 20px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">

            <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 16px;">
                <div style="width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, #7c3aed, #4c1d95); display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; box-shadow: 0 2px 8px rgba(124,58,237,.45); flex-shrink: 0;">&#127913;</div>
                <div>
                    <div class="u-strong u-fs-15"><?php echo $h($greg['name']); ?> <span class="u-muted u-fw-400">(VoIPMonk)</span></div>
                    <span class="badge <?php echo $greg['enabled'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
                        <?php echo t($greg['enabled'] ? 'ai_voip_engineer.status_active' : 'ai_voip_engineer.status_disabled'); ?>
                    </span>
                    <span class="badge badge-info u-fs-11" style="margin-left: 4px;">
                        <?php echo t('ai_voip_engineer.month_tokens') . ': ' . number_format($month_tokens); ?>
                    </span>
                    <?php if ($unread > 0): ?>
                        <span class="badge badge-warning u-fs-11" style="margin-left: 4px;"><?php echo $unread . ' ' . t('ai_voip_engineer.unread'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_enabled" value="1" <?php echo $greg['enabled'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.enable'); ?></label>
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.enable_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.name_label'); ?></label>
                    <input type="text" class="form-control" name="greg_name" value="<?php echo $h($greg['name']); ?>" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.source_label'); ?></label>
                    <select class="form-control" name="greg_model_source">
                        <?php foreach ($sources as $id => $label): ?>
                            <option value="<?php echo $h($id); ?>" <?php echo $greg['model_source'] === $id ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.source_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.quiet_label'); ?></label>
                    <input type="text" class="form-control" name="greg_quiet_hours" value="<?php echo $h($greg['quiet_hours']); ?>" placeholder="<?php echo t('ai_voip_engineer.quiet_hint'); ?>" pattern="^\d{2}:\d{2}-\d{2}:\d{2}$">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.quiet_help'); ?></div>
                </div>
            </div>

            <div class="u-grid-2" style="gap: 16px;">
                <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_show_avatar" value="1" <?php echo $greg['show_avatar'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.avatar_label'); ?></label>
                <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_show_tokens" value="1" <?php echo $greg['show_tokens'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.tokens_label'); ?></label>
                <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_notice_email" value="1" <?php echo $greg['notice_email'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.email_label'); ?></label>
            </div>

            <div style="margin-top: 14px; display: flex; gap: 10px; align-items: center;">
                <button type="submit" name="save_greg" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
                <a class="btn btn-secondary btn-sm" href="/chat"><i class="fas fa-comments"></i> <?php echo t('ai_voip_engineer.open_chat'); ?></a>
            </div>
        </form>

        <div class="u-muted u-fs-12 u-mt-14" style="border-top: 1px solid var(--border-color); padding-top: 12px;">
            <i class="fas fa-shield-halved"></i> <?php echo t('ai_voip_engineer.audit_note'); ?>
        </div>
        <div class="u-muted u-fs-12 u-mt-6" style="color: var(--warning);"><i class="fas fa-triangle-exclamation"></i> <?php echo t('ai_voip_engineer.limits_warning'); ?></div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-book u-primary"></i> <?php echo t('ai_voip_engineer.notes_title'); ?></div>
        <span class="badge badge-secondary u-fs-11" style="font-family: monospace; font-size: 10px;"><?php echo $h($journal_agents_path); ?></span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 14px;"><?php echo t('ai_voip_engineer.notes_intro'); ?></div>

        <form method="POST" style="margin-bottom: 18px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="form-group">
                <label class="form-label"><?php echo t('ai_voip_engineer.notes_agents_label'); ?></label>
                <textarea class="form-control" name="greg_agents" rows="10" maxlength="20000" spellcheck="false"
                          style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;"
                          placeholder="<?php echo $h(t('ai_voip_engineer.notes_agents_placeholder')); ?>"><?php echo $h($agents); ?></textarea>
            </div>
            <button type="submit" name="save_greg_notes" value="1" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('ai_voip_engineer.notes_save'); ?>
            </button>
        </form>

        <form method="POST" style="border-top: 1px solid var(--border-color); padding-top: 14px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.notes_add_title'); ?></label>
                    <input type="text" class="form-control" name="note_title" maxlength="120"
                           placeholder="<?php echo $h(t('ai_voip_engineer.notes_add_title_ph')); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.notes_add_body'); ?></label>
                    <input type="text" class="form-control" name="note_body" maxlength="300"
                           placeholder="<?php echo $h(t('ai_voip_engineer.notes_add_body_ph')); ?>">
                </div>
            </div>
            <button type="submit" name="append_greg_note" value="1" class="btn btn-secondary">
                <i class="fas fa-pen"></i> <?php echo t('ai_voip_engineer.notes_add'); ?>
            </button>
        </form>

        <div style="margin-top: 16px;">
            <div class="u-strong u-fs-12" style="margin-bottom: 8px;"><?php echo t('ai_voip_engineer.notes_recent'); ?></div>
            <?php if (trim($journal_recent) === ''): ?>
                <div class="u-muted u-fs-12"><?php echo t('ai_voip_engineer.notes_empty'); ?></div>
            <?php else: ?>
                <pre style="background: var(--bg-main); padding: 12px; border-radius: 6px; white-space: pre-wrap; font-size: 12px; margin: 0; max-height: 260px; overflow: auto;"><?php echo $h($journal_recent); ?></pre>
            <?php endif; ?>
        </div>

        <div class="u-muted u-fs-11" style="margin-top: 12px;">
            <i class="fas fa-folder-open"></i> <?php echo $h(sprintf(t('ai_voip_engineer.notes_where'), $journal_dir)); ?>
        </div>
    </div>
</div>
