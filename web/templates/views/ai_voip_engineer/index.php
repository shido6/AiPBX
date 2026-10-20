<?php
/** @var array $greg @var array $anam @var array $brief @var string $brief_text @var array $onboarding
 *  @var array $duty @var array|null $handoff
 *  @var array $systems @var string $systems_text @var array $readiness
 *  @var array $observability
 *  @var array $asterisk_docs
 *  @var int $month_tokens @var int $unread @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$tempColors = ['cold' => '#64748b', 'cool' => '#0ea5e9', 'warm' => '#f59e0b', 'hot' => '#10b981'];
$tempColor = $tempColors[$readiness['temperature']] ?? $tempColors['cold'];
$sources = [
    'bigpickle' => t('ai_voip_engineer.source_bigpickle'),
    'system'    => t('ai_voip_engineer.source_system'),
    'ling'      => t('ai_voip_engineer.source_ling'),
];
?>

<div class="card" style="margin-bottom: 16px; border-left: 3px solid <?php echo $tempColor; ?>;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-temperature-half u-primary"></i> <?php echo t('ai_voip_engineer.ready_title'); ?></div>
        <span class="badge u-fs-11" style="background: <?php echo $tempColor; ?>; color: #fff;">
            <?php echo (int) $readiness['score']; ?>% · <?php echo t('ai_voip_engineer.ready_temp_' . $readiness['temperature']); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <div style="font-size: 34px; font-weight: 800; line-height: 1; color: <?php echo $tempColor; ?>;"><?php echo (int) $readiness['score']; ?>%</div>
            <div style="flex: 1; min-width: 220px;">
                <div style="height: 10px; border-radius: 6px; background: var(--bg-main); border: 1px solid var(--border-color); overflow: hidden;">
                    <div style="height: 100%; width: <?php echo (int) $readiness['score']; ?>%; background: linear-gradient(90deg, #64748b, <?php echo $tempColor; ?>);"></div>
                </div>
                <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.ready_scale'); ?></div>
            </div>
        </div>

        <div class="u-fs-12" style="padding: 10px 12px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border-color);">
            <?php if ($readiness['missing']): ?>
                <i class="fas fa-triangle-exclamation" style="color: <?php echo $tempColor; ?>"></i>
                <?php echo $h(sprintf(t('ai_voip_engineer.ready_alert'), implode(', ', $readiness['missing']))); ?>
            <?php else: ?>
                <i class="fas fa-circle-check" style="color: var(--success);"></i> <?php echo t('ai_voip_engineer.ready_complete'); ?>
            <?php endif; ?>
            <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.ready_why'); ?></div>
            <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.ready_docs_hint'); ?></div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
            <?php foreach ($readiness['parts'] as $part): ?>
                <div style="flex: 1; min-width: 170px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-card);">
                    <div class="u-fs-12 u-strong">
                        <i class="fas <?php echo $part['done'] ? 'fa-circle-check' : 'fa-circle-half-stroke'; ?>" style="color: <?php echo $part['done'] ? 'var(--success)' : 'var(--warning)'; ?>"></i>
                        <?php echo $h($part['label']); ?>
                    </div>
                    <div class="u-muted u-fs-11">
                        <?php echo $h($part['detail']); ?> · <?php echo (int) $part['points']; ?>/<?php echo (int) $part['max']; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
            <a class="btn btn-secondary btn-sm" href="?systems=template"><i class="fas fa-download"></i> <?php echo t('ai_voip_engineer.sys_download_template'); ?></a>
            <a class="btn btn-secondary btn-sm" href="#greg-systems"><i class="fas fa-diagram-project"></i> <?php echo t('ai_voip_engineer.ready_add_systems'); ?></a>
            <?php if (!$brief['exists']): ?>
                <a class="btn btn-secondary btn-sm" href="#greg-brief"><i class="fas fa-file-lines"></i> <?php echo t('ai_voip_engineer.ready_add_business'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$obsColor = $tempColors[$observability['temperature']] ?? $tempColors['cold'];
$obsDeg = max(0, min(100, (int) $observability['score'])) * 3.6;
$obsStates = [
    'on'      => ['var(--success)', 'fa-circle-check'],
    'limited' => ['var(--warning)', 'fa-circle-half-stroke'],
    'down'    => ['var(--danger)', 'fa-triangle-exclamation'],
    'off'     => ['#94a3b8', 'fa-circle-xmark'],
];
?>
<div class="card" style="margin-bottom: 16px; border-left: 3px solid <?php echo $obsColor; ?>;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-satellite-dish u-primary"></i> <?php echo t('ai_voip_engineer.obs_title'); ?></div>
        <span class="badge u-fs-11" style="background: <?php echo $obsColor; ?>; color: #fff;">
            <?php echo (int) $observability['score']; ?>% · <?php echo t('ai_voip_engineer.obs_temp_' . $observability['temperature']); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap;">
            <div style="width: 108px; height: 108px; border-radius: 50%; flex-shrink: 0; display: grid; place-items: center;
                        background: conic-gradient(<?php echo $obsColor; ?> 0deg <?php echo $obsDeg; ?>deg, var(--bg-main) <?php echo $obsDeg; ?>deg 360deg);
                        border: 1px solid var(--border-color);">
                <div style="width: 76px; height: 76px; border-radius: 50%; background: var(--bg-card); display: flex; flex-direction: column;
                            align-items: center; justify-content: center; line-height: 1.05;">
                    <span style="font-size: 24px; font-weight: 800; color: <?php echo $obsColor; ?>;"><?php echo (int) $observability['score']; ?>%</span>
                    <span class="u-muted" style="font-size: 9px; text-transform: uppercase; letter-spacing: .04em;"><?php echo t('ai_voip_engineer.obs_see'); ?></span>
                </div>
            </div>
            <div style="flex: 1; min-width: 240px;">
                <div class="u-fs-13 u-strong"><?php echo t('ai_voip_engineer.obs_intro'); ?></div>
                <div style="height: 10px; border-radius: 6px; background: var(--bg-main); border: 1px solid var(--border-color); overflow: hidden; margin-top: 10px;">
                    <div style="height: 100%; width: <?php echo (int) $observability['score']; ?>%; background: linear-gradient(90deg, #64748b, <?php echo $obsColor; ?>);"></div>
                </div>
                <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.obs_scale'); ?></div>
            </div>
        </div>

        <div class="u-fs-12" style="margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border-color);">
            <?php if ($observability['missing']): ?>
                <i class="fas fa-triangle-exclamation" style="color: <?php echo $obsColor; ?>"></i>
                <?php echo $h(sprintf(t('ai_voip_engineer.obs_alert'), implode(', ', $observability['missing']))); ?>
            <?php else: ?>
                <i class="fas fa-circle-check" style="color: var(--success);"></i> <?php echo t('ai_voip_engineer.obs_complete'); ?>
            <?php endif; ?>
            <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.obs_why'); ?></div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
            <?php foreach ($observability['parts'] as $part):
                [$stateColor, $stateIcon] = $obsStates[$part['state']] ?? $obsStates['off'];
            ?>
                <div style="flex: 1; min-width: 185px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-card);">
                    <div class="u-fs-12 u-strong" style="display: flex; align-items: center; gap: 6px;">
                        <i class="fas <?php echo $stateIcon; ?>" style="color: <?php echo $stateColor; ?>"></i>
                        <?php echo $h($part['label']); ?>
                        <span class="u-muted u-fw-400" style="margin-left: auto; font-variant-numeric: tabular-nums;"><?php echo (int) $part['points']; ?>/<?php echo (int) $part['max']; ?></span>
                    </div>
                    <div class="u-muted u-fs-11" style="margin-top: 2px;">
                        <?php echo $h($part['detail']); ?>
                        <?php if (preg_match('#^https?://#i', (string) $part['url'])): ?>
                            <a href="<?php echo $h($part['url']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo $h($part['label']); ?>"><i class="fas fa-arrow-up-right-from-square"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($observability['next']): ?>
        <div style="margin-top: 14px; border-top: 1px solid var(--border-color); padding-top: 12px;">
            <div class="u-strong u-fs-12"><i class="fas fa-list-check u-primary"></i> <?php echo t('ai_voip_engineer.obs_next_title'); ?></div>
            <div class="u-muted u-fs-11" style="margin: 3px 0 8px;"><?php echo t('ai_voip_engineer.obs_next_intro'); ?></div>
            <?php foreach ($observability['next'] as $step): ?>
                <div style="display: flex; gap: 9px; align-items: center; flex-wrap: wrap; padding: 7px 9px; margin-bottom: 6px; border: 1px solid var(--border-color); border-radius: 7px; background: var(--bg-card);">
                    <span class="badge badge-secondary u-fs-10" style="font-variant-numeric: tabular-nums; flex-shrink: 0;">+<?php echo (int) $step['gain']; ?> <?php echo t('ai_voip_engineer.obs_next_pts'); ?></span>
                    <span class="u-fs-12" style="flex: 1; min-width: 220px;">
                        <strong><?php echo $h($step['label']); ?></strong> — <?php echo $h($step['action']); ?>
                    </span>
                    <?php if (preg_match('#^/#', (string) $step['url'])): ?>
                        <a class="btn btn-secondary btn-sm" href="<?php echo $h($step['url']); ?>"><?php echo t('ai_voip_engineer.obs_next_do'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px;">
            <a class="btn btn-secondary btn-sm" href="/ai-echo"><i class="fas fa-sliders"></i> <?php echo t('ai_voip_engineer.obs_configure'); ?></a>
            <span class="u-muted u-fs-11"><i class="fas fa-lock"></i> <?php echo t('ai_voip_engineer.obs_passive'); ?></span>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-book-open u-primary"></i> <?php echo t('ai_voip_engineer.ref_title'); ?></div>
        <span class="badge <?php echo $asterisk_docs['ready'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
            <?php echo $asterisk_docs['ready']
                ? $h(sprintf(t('ai_voip_engineer.ref_pages'), $asterisk_docs['pages'], $asterisk_docs['catalog']))
                : t('ai_voip_engineer.ref_none'); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12"><?php echo t('ai_voip_engineer.ref_intro'); ?></div>
        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.ref_not_scored'); ?></div>

        <?php if ($asterisk_docs['ready']): ?>
            <div class="u-fs-12 u-mt-10" style="padding: 10px 12px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border-color);">
                <i class="fas fa-circle-check" style="color: var(--success);"></i>
                <?php echo $h(sprintf(
                    t('ai_voip_engineer.ref_status'),
                    $asterisk_docs['pages'],
                    $asterisk_docs['catalog'],
                    max(1, (int) round($asterisk_docs['bytes'] / 1024)),
                    gmdate('Y-m-d H:i', $asterisk_docs['updated']) . ' UTC'
                )); ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 12px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <button type="submit" name="sync_asterisk_docs" value="1" class="btn btn-secondary btn-sm">
                <i class="fas fa-cloud-arrow-down"></i>
                <?php echo t($asterisk_docs['ready'] ? 'ai_voip_engineer.ref_refresh' : 'ai_voip_engineer.ref_download'); ?>
            </button>
            <?php if ($asterisk_docs['ready']): ?>
                <button type="submit" name="delete_asterisk_docs" value="1" class="btn btn-secondary btn-sm"
                        onclick="return confirm(<?php echo $h(json_encode(t('ai_voip_engineer.ref_remove_confirm'))); ?>);">
                    <i class="fas fa-trash"></i> <?php echo t('ai_voip_engineer.ref_remove'); ?>
                </button>
            <?php endif; ?>
            <a class="u-muted u-fs-11" href="<?php echo $h($asterisk_docs['source']); ?>" target="_blank" rel="noopener noreferrer">
                docs.asterisk.org <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </form>

        <div class="u-muted u-fs-11" style="margin-top: 8px;">
            <i class="fas fa-network-wired"></i> <?php echo t('ai_voip_engineer.ref_network'); ?>
        </div>

        <div class="u-muted u-fs-11" style="margin-top: 6px;">
            <i class="fas fa-copyright"></i>
            <?php echo $h(sprintf(t('ai_voip_engineer.ref_license'), $asterisk_docs['license'])); ?>
        </div>
    </div>
</div>

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
                    <div class="u-muted u-fs-11"><?php echo t('ai_voip_engineer.role'); ?></div>
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
                <label class="u-check-label" title="<?php echo $h(t('ai_voip_engineer.auto_fix_help')); ?>"><input type="checkbox" class="u-accent" name="greg_auto_fix" value="1" <?php echo $greg['auto_fix'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.auto_fix_label'); ?></label>
            </div>
            <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.auto_fix_help'); ?></div>

            <div style="margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                <div class="u-strong u-fs-13"><i class="fas fa-user-clock u-primary"></i> <?php echo t('ai_voip_engineer.relief_title'); ?></div>
                <div class="u-muted u-fs-12" style="margin: 4px 0 10px;"><?php echo t('ai_voip_engineer.relief_intro'); ?></div>
                <div class="u-grid-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_relief_enabled" value="1" <?php echo $greg['relief_enabled'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.relief_enable'); ?></label>
                        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.relief_enable_hint'); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_voip_engineer.relief_name_label'); ?></label>
                        <input type="text" class="form-control" name="greg_relief_name" maxlength="30" value="<?php echo $h($greg['relief_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_voip_engineer.relief_source_label'); ?></label>
                        <select class="form-control" name="greg_relief_model_source">
                            <?php foreach ($sources as $id => $label): ?>
                                <option value="<?php echo $h($id); ?>" <?php echo $greg['relief_model_source'] === $id ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="u-muted u-fs-11" style="margin-top: 24px;"><?php echo t('ai_voip_engineer.relief_source_hint'); ?></div>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="u-check-label"><input type="checkbox" class="u-accent" name="greg_council" value="1" <?php echo $greg['council'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.council_label'); ?></label>
                        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.council_hint'); ?></div>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <span class="badge <?php echo $duty['relief'] ? 'badge-info' : 'badge-success'; ?> u-fs-11">
                        <?php echo $h(sprintf(t('ai_voip_engineer.relief_on_duty'), $duty['name'])); ?>
                    </span>
                    <?php if ($greg['relief_enabled']): ?>
                        <?php if ($duty['relief']): ?>
                            <button type="submit" name="take_back_greg" value="1" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-rotate-left"></i> <?php echo $h(sprintf(t('ai_voip_engineer.relief_take_back'), $greg['name'])); ?>
                            </button>
                        <?php else: ?>
                            <button type="submit" name="hand_off_greg" value="1" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-right-from-bracket"></i> <?php echo $h(sprintf(t('ai_voip_engineer.relief_hand_off'), $greg['relief_name'])); ?>
                            </button>
                        <?php endif; ?>
                        <?php if (is_array($handoff) && $handoff['manual'] !== null && $handoff['manual_until'] > time()): ?>
                            <span class="u-muted u-fs-11"><?php echo $h(sprintf(t('ai_voip_engineer.relief_manual_until'), date('H:i', $handoff['manual_until']))); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="u-muted u-fs-11 u-mt-6"><i class="fas fa-list-check"></i> <?php echo t('ai_voip_engineer.relief_handoff_note'); ?></div>
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

<div class="card" id="greg-brief" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-file-lines u-primary"></i> <?php echo t('ai_voip_engineer.brief_title'); ?></div>
        <span class="badge <?php echo $brief['exists'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
            <?php echo $brief['exists']
                ? t('ai_voip_engineer.brief_status_yes') . ' — ' . number_format($brief['chars']) . ' ' . t('ai_voip_engineer.brief_chars')
                : t('ai_voip_engineer.brief_status_no'); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 10px;"><?php echo t('ai_voip_engineer.brief_intro'); ?></div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border-color);">
            <i class="fas fa-user-plus u-primary"></i>
            <span class="u-fs-12">
                <?php if ($onboarding['active'] && $onboarding['answered'] > 0): ?>
                    <?php echo $h(sprintf(t('ai_voip_engineer.onb_status_active_answers'), $onboarding['step'], $onboarding['total'], $onboarding['answered'])); ?>
                <?php elseif ($onboarding['active']): ?>
                    <?php echo $h(sprintf(t('ai_voip_engineer.onb_status_active'), $onboarding['step'], $onboarding['total'])); ?>
                <?php elseif ($onboarding['answered'] > 0): ?>
                    <?php echo $h(sprintf(t('ai_voip_engineer.onb_status_chat'), $onboarding['answered'], $onboarding['total'])); ?>
                <?php elseif ($brief['exists']): ?>
                    <?php echo $h(t('ai_voip_engineer.onb_status_doc')); ?>
                <?php else: ?>
                    <?php echo $h(t('ai_voip_engineer.onb_status_none')); ?>
                <?php endif; ?>
            </span>
            <?php if ($onboarding['active'] || $onboarding['answered'] < $onboarding['total']): ?>
                <form method="POST" style="margin-left: auto;">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <button type="submit" name="start_greg_onboarding" value="1" class="btn btn-secondary btn-sm">
                        <i class="fas fa-comments"></i>
                        <?php echo t($onboarding['active'] ? 'ai_voip_engineer.onb_continue' : 'ai_voip_engineer.onb_start'); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px;">
            <a class="btn btn-secondary btn-sm" href="?brief=template"><i class="fas fa-download"></i> <?php echo t('ai_voip_engineer.brief_download_template'); ?></a>
            <?php if ($brief['exists']): ?>
                <a class="btn btn-secondary btn-sm" href="?brief=current"><i class="fas fa-download"></i> <?php echo t('ai_voip_engineer.brief_download_current'); ?></a>
                <span class="u-muted u-fs-11"><?php echo $h(sprintf(t('ai_voip_engineer.brief_updated'), date('Y-m-d H:i', $brief['updated']))); ?></span>
            <?php endif; ?>
            <span class="u-muted u-fs-11"><?php echo t('ai_voip_engineer.brief_how'); ?></span>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.brief_file'); ?></label>
                    <input type="file" class="form-control" name="greg_brief_file" accept=".md,.markdown,.txt,text/markdown,text/plain">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.brief_file_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.brief_text'); ?></label>
                    <textarea class="form-control" name="greg_brief" rows="8" maxlength="<?php echo (int) \GregBrief::MAX_CHARS; ?>"
                              spellcheck="true"
                              placeholder="<?php echo $h(t('ai_voip_engineer.brief_text_ph')); ?>"
                              style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;"><?php echo $h($brief_text); ?></textarea>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" name="save_greg_brief" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('ai_voip_engineer.brief_save'); ?></button>
                <?php if ($brief['exists']): ?>
                    <button type="submit" name="delete_greg_brief" value="1" class="btn btn-secondary btn-sm"
                            onclick="return confirm(<?php echo $h(json_encode(t('ai_voip_engineer.brief_remove_confirm'))); ?>);">
                        <i class="fas fa-trash"></i> <?php echo t('ai_voip_engineer.brief_remove'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <div class="u-muted u-fs-11" style="margin-top: 12px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <i class="fas fa-shield-halved"></i> <?php echo t('ai_voip_engineer.brief_note'); ?>
        </div>
    </div>
</div>

<div class="card" id="greg-systems" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-diagram-project u-primary"></i> <?php echo t('ai_voip_engineer.sys_title'); ?></div>
        <span class="badge <?php echo $systems['exists'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
            <?php echo $systems['exists']
                ? t('ai_voip_engineer.sys_status_yes') . ' — ' . number_format($systems['chars']) . ' ' . t('ai_voip_engineer.sys_chars')
                : t('ai_voip_engineer.sys_status_no'); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 10px;"><?php echo t('ai_voip_engineer.sys_intro'); ?></div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px;">
            <a class="btn btn-secondary btn-sm" href="?systems=template"><i class="fas fa-download"></i> <?php echo t('ai_voip_engineer.sys_download_template'); ?></a>
            <?php if ($systems['exists']): ?>
                <a class="btn btn-secondary btn-sm" href="?systems=current"><i class="fas fa-download"></i> <?php echo t('ai_voip_engineer.sys_download_current'); ?></a>
                <span class="u-muted u-fs-11"><?php echo $h(sprintf(t('ai_voip_engineer.sys_updated'), date('Y-m-d H:i', $systems['updated']))); ?></span>
            <?php endif; ?>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.sys_file'); ?></label>
                    <input type="file" class="form-control" name="greg_systems_file" accept=".md,.markdown,.txt,text/markdown,text/plain">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.sys_file_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.sys_text'); ?></label>
                    <textarea class="form-control" name="greg_systems" rows="8" maxlength="<?php echo (int) \GregSystems::MAX_CHARS; ?>"
                              spellcheck="true"
                              placeholder="<?php echo $h(t('ai_voip_engineer.sys_text_ph')); ?>"
                              style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;"><?php echo $h($systems_text); ?></textarea>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" name="save_greg_systems" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('ai_voip_engineer.sys_save'); ?></button>
                <?php if ($systems['exists']): ?>
                    <button type="submit" name="delete_greg_systems" value="1" class="btn btn-secondary btn-sm"
                            onclick="return confirm(<?php echo $h(json_encode(t('ai_voip_engineer.sys_remove_confirm'))); ?>);">
                        <i class="fas fa-trash"></i> <?php echo t('ai_voip_engineer.sys_remove'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <div class="u-muted u-fs-11" style="margin-top: 12px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <i class="fas fa-shield-halved"></i> <?php echo t('ai_voip_engineer.sys_note'); ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-video u-primary"></i> <?php echo t('ai_voip_engineer.anam_title'); ?></div>
        <span class="badge badge-info u-fs-10" style="margin-left: 8px;"><?php echo t('ai_voip_engineer.anam_addon'); ?></span>
        <span class="badge <?php echo $anam['enabled'] && $anam['configured'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
            <?php
            echo $anam['enabled'] && $anam['configured']
                ? t('ai_voip_engineer.anam_status_on')
                : ($anam['configured'] ? t('ai_voip_engineer.anam_status_off') : t('ai_voip_engineer.anam_status_unset'));
            ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 14px;">
            <?php echo t('ai_voip_engineer.anam_intro'); ?>
            <a href="<?php echo htmlspecialchars(\AnamService::HOWTO, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer"><?php echo t('ai_voip_engineer.anam_howto'); ?> <i class="fas fa-arrow-up-right-from-square u-fs-10"></i></a>
        </div>
        <div class="u-fs-12" style="margin: -8px 0 14px; padding: 8px 10px; border-radius: 6px; background: var(--bg-main); border: 1px solid var(--border-color);">
            <i class="fas fa-scale-balanced u-muted"></i> <?php echo t('ai_voip_engineer.anam_pricing_note'); ?>
            <a href="<?php echo htmlspecialchars(\AnamService::PRICING, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer">anam.ai/pricing <i class="fas fa-arrow-up-right-from-square u-fs-10"></i></a>
            <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_pricing_how'); ?></div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.anam_persona_label'); ?></label>
                    <input type="text" class="form-control" name="anam_persona_id" maxlength="64"
                           value="<?php echo $h($anam['persona_id']); ?>"
                           placeholder="<?php echo $h(t('ai_voip_engineer.anam_persona_ph')); ?>"
                           spellcheck="false" style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_persona_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.anam_key_label'); ?></label>
                    <input type="password" class="form-control" name="anam_api_key" autocomplete="new-password"
                           placeholder="<?php echo $h($anam['key_set'] ? $anam['key_masked'] : t('ai_voip_engineer.anam_key_ph')); ?>">
                    <div class="u-muted u-fs-11 u-mt-4">
                        <?php echo t('ai_voip_engineer.anam_key_help'); ?>
                        <?php if ($anam['key_set']): ?>
                            <label class="u-check-label u-fs-11" style="display: inline-flex; margin-left: 8px;">
                                <input type="checkbox" class="u-accent" name="anam_key_clear" value="1"> <?php echo t('ai_voip_engineer.anam_key_clear'); ?>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_voip_engineer.anam_limit_label'); ?></label>
                    <input type="number" class="form-control" name="anam_max_seconds"
                           value="<?php echo (int) $anam['limit']; ?>"
                           min="<?php echo \AnamService::MIN_SECONDS; ?>" max="<?php echo \AnamService::MAX_SECONDS; ?>" step="30">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_limit_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="u-check-label"><input type="checkbox" class="u-accent" name="anam_enabled" value="1" <?php echo $anam['enabled'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.anam_enable'); ?></label>
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_enable_help'); ?></div>
                    <label class="u-check-label u-mt-6"><input type="checkbox" class="u-accent" name="anam_listen" value="1" <?php echo $anam['listen'] ? 'checked' : ''; ?>> <?php echo t('ai_voip_engineer.anam_listen'); ?></label>
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_listen_help'); ?></div>
                </div>
            </div>

            <div style="margin-top: 6px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" name="save_greg_anam" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
                <button type="button" class="btn btn-secondary btn-sm" id="anam-test" onclick="anamTest()"><i class="fas fa-vial"></i> <?php echo t('ai_voip_engineer.anam_test'); ?></button>
                <span id="anam-test-result" class="u-fs-12 u-muted"></span>
            </div>
        </form>

        <div style="margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 12px;">
            <div class="u-strong u-fs-13" style="margin-bottom: 6px;"><i class="fas fa-users u-primary"></i> <?php echo t('ai_voip_engineer.anam_meeting_title'); ?></div>
            <div class="u-muted u-fs-12" style="margin-bottom: 10px;"><?php echo t('ai_voip_engineer.anam_meeting_intro'); ?></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                <div class="u-grid-2" style="gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_voip_engineer.anam_meeting_url'); ?></label>
                        <input type="url" class="form-control" name="meeting_url" maxlength="500"
                               placeholder="https://meet.google.com/…" spellcheck="false">
                        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_meeting_hosts'); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_voip_engineer.anam_meeting_when'); ?></label>
                        <input type="datetime-local" class="form-control" name="meeting_at">
                        <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_voip_engineer.anam_meeting_when_help'); ?></div>
                    </div>
                </div>
                <label class="u-check-label u-fs-12"><input type="checkbox" class="u-accent" name="meeting_group" value="1" checked> <?php echo t('ai_voip_engineer.anam_meeting_group'); ?></label>
                <div style="margin-top: 10px;">
                    <button type="submit" name="join_anam_meeting" value="1" class="btn btn-secondary btn-sm"
                            <?php echo $anam['configured'] ? '' : 'disabled'; ?>>
                        <i class="fas fa-right-to-bracket"></i> <?php echo t('ai_voip_engineer.anam_meeting_send'); ?>
                    </button>
                    <?php if (!$anam['configured']): ?>
                        <span class="u-muted u-fs-11"><?php echo t('ai_voip_engineer.anam_not_configured'); ?></span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="u-muted u-fs-11" style="margin-top: 12px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <i class="fas fa-lock"></i> <?php echo t('ai_voip_engineer.anam_secure'); ?>
        </div>
    </div>
</div>

<script>
function anamTest() {
    var btn = document.getElementById('anam-test');
    var out = document.getElementById('anam-test-result');
    var csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    if (!btn || !out) return;
    btn.disabled = true;
    out.className = 'u-fs-12 u-muted';
    out.textContent = '…';
    var fd = new FormData();
    fd.append('action', 'test');
    fd.append('csrf_token', csrf);
    fetch('<?php echo htmlspecialchars((defined("WEBROOT") ? WEBROOT : "") . "/api/anam.php", ENT_QUOTES); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            btn.disabled = false;
            if (r && r.success) {
                out.className = 'u-fs-12';
                out.style.color = 'var(--success)';
                out.textContent = '✔ ' + (r.name || 'OK');
            } else {
                out.className = 'u-fs-12';
                out.style.color = 'var(--danger)';
                out.textContent = '✖ ' + ((r && r.error) || '—');
            }
        })
        .catch(function () {
            btn.disabled = false;
            out.className = 'u-fs-12';
            out.style.color = 'var(--danger)';
            out.textContent = '✖';
        });
}
</script>

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
