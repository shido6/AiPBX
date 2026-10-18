<?php
/** @var array $greg @var int $month_tokens @var int $unread @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$sources = [
    'system'    => t('ai_voip_engineer.source_system'),
    'ling'      => t('ai_voip_engineer.source_ling'),
    'bigpickle' => t('ai_voip_engineer.source_bigpickle'),
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
