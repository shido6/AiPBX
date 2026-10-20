<?php
/** @var array $echo @var string $setup_steps @var array $runs @var ?string $runs_error
 *  @var ?array $last_run @var string $csrf_token @var array $hep_compute */
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

<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-satellite-dish u-primary"></i> <?php echo t('ai_echo.hep_title'); ?></div>
        <span class="badge <?php echo $hep['enabled'] ? 'badge-success' : 'badge-secondary'; ?> u-fs-11"
              style="white-space:normal;line-height:1.4;text-align:left;max-width:100%;">
            <?php echo $h(HepSettingsService::describe($hep)); ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 14px;"><?php echo t('ai_echo.hep_intro'); ?></div>

        <div style="border:1px solid var(--border-color);border-radius:8px;padding:10px 12px;margin-bottom:14px;">
            <div class="u-strong u-fs-12" style="margin-bottom:6px;">
                <i class="fas fa-server"></i> <?php echo t('ai_echo.hep_compute_title'); ?>
            </div>
            <?php if ($hep_compute['measured']): ?>
                <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:8px;">
                    <div>
                        <div class="u-muted u-fs-10"><?php echo t('ai_echo.hep_compute_cpu'); ?></div>
                        <div class="u-strong u-fs-12">
                            <?php echo sprintf(t('ai_echo.hep_compute_cores'), (int) $hep_compute['cores']); ?>
                            <span class="u-muted u-fs-11">· <?php echo sprintf(t('ai_echo.hep_compute_load'), $hep_compute['load_pct'] === null ? '—' : (string) $hep_compute['load_pct']); ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="u-muted u-fs-10"><?php echo t('ai_echo.hep_compute_ram'); ?></div>
                        <div class="u-strong u-fs-12">
                            <?php echo sprintf(t('ai_echo.hep_compute_of'),
                                HepComputeService::human((int) $hep_compute['mem_available']),
                                HepComputeService::human((int) $hep_compute['mem_total'])); ?>
                        </div>
                    </div>
                    <div>
                        <div class="u-muted u-fs-10"><?php echo t('ai_echo.hep_compute_disk'); ?></div>
                        <div class="u-strong u-fs-12">
                            <?php echo sprintf(t('ai_echo.hep_compute_of'),
                                HepComputeService::human((int) $hep_compute['disk_free']),
                                HepComputeService::human((int) $hep_compute['disk_total'])); ?>
                        </div>
                    </div>
                </div>
                <?php
                $tone = ['ok' => 'var(--success)', 'tight' => 'var(--warning)', 'low' => 'var(--danger)'];
                $icon = ['ok' => 'fa-circle-check', 'tight' => 'fa-triangle-exclamation', 'low' => 'fa-circle-xmark'];
                foreach (['one', 'both'] as $kind):
                    $level = $hep_compute[$kind]['level'];
                    ?>
                    <div class="u-fs-11" style="color:<?php echo $tone[$level] ?? 'var(--text-muted)'; ?>;margin-bottom:2px;">
                        <i class="fas <?php echo $icon[$level] ?? 'fa-circle-info'; ?>"></i>
                        <span class="u-strong"><?php echo t('ai_echo.hep_compute_' . $kind . '_label'); ?>:</span>
                        <?php echo t('ai_echo.hep_compute_' . $kind . '_' . $level); ?>
                    </div>
                <?php endforeach; ?>
                <div class="u-muted u-fs-10" style="margin-top:6px;"><?php echo t('ai_echo.hep_compute_note'); ?></div>
            <?php else: ?>
                <div class="u-muted u-fs-11"><?php echo t('ai_echo.hep_compute_unavailable'); ?></div>
            <?php endif; ?>
        </div>

        <div class="u-muted u-fs-11" style="margin-bottom: 12px;display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;">
            <i class="fas fa-heart-pulse"></i>
            <span>
                <span class="u-strong"><?php echo t('ai_echo.hep_health'); ?>:</span>
                <?php if ($hep_health === null): ?>
                    <?php echo t('ai_echo.hep_health_none'); ?>
                <?php else: ?>
                    <span class="badge badge-<?php echo $hep_health['status'] === 'ok' ? 'success' : ($hep_health['status'] === 'fail' ? 'danger' : ($hep_health['status'] === 'warn' ? 'warning' : 'secondary')); ?>">
                        <?php echo $h(t('health.status_' . $hep_health['status'])); ?>
                    </span>
                    <?php if ($hep_health['checked_at'] !== null): ?>
                        <span class="u-muted"><?php echo $h($hep_health['checked_at']); ?></span>
                    <?php endif; ?>
                    <?php if ($hep_health['message'] !== ''): ?>
                        <span style="display:block;margin-top:2px;"><?php echo $h($hep_health['message']); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="/health" class="u-fs-11"><?php echo t('ai_echo.hep_health_link'); ?></a>
            </span>
        </div>

        <div class="u-muted u-fs-11" style="margin-bottom: 12px;">
            <?php if (!$hep_current['exists']): ?>
                <i class="fas fa-triangle-exclamation" style="color: var(--warning);"></i> <?php echo t('ai_echo.hep_file_missing'); ?>
            <?php else: ?>
                <i class="fas fa-file-code"></i> <?php echo t('ai_echo.hep_current'); ?>:
                <code>enabled = <?php echo $hep_current['enabled'] ? 'yes' : 'no'; ?></code>
                · <code><?php echo $h($hep_current['capture_address']); ?></code>
                · <code>capture_id = <?php echo $h($hep_current['capture_id']); ?></code>
            <?php endif; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_mode'); ?></label>
                    <select class="form-control" name="hep_mode">
                        <?php foreach (['builtin', 'homer', 'voipmonitor', 'both', 'external', 'off'] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo $hep['mode'] === $m ? 'selected' : ''; ?>>
                                <?php echo t('ai_echo.hep_mode_' . $m); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_ui_url'); ?></label>
                    <input type="url" class="form-control" name="hep_ui_url" value="<?php echo $h($hep['form_ui_url']); ?>" placeholder="http://homer.local:9080">
                    <div class="u-muted u-fs-11 u-mt-4"><?php echo t('ai_echo.hep_ui_hint'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_host'); ?></label>
                    <input type="text" class="form-control" name="hep_host" value="<?php echo $h($hep['form_host']); ?>" placeholder="127.0.0.1">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_port'); ?></label>
                    <input type="text" class="form-control" name="hep_port" inputmode="numeric" value="<?php echo (int) $hep['form_port']; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_password'); ?></label>
                    <input type="password" class="form-control" name="hep_password" autocomplete="new-password" value=""
                           placeholder="<?php echo $h($hep['password_set'] ? t('ai_tts.keep_secret') : HepSettingsService::DEFAULT_PASSWORD); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.hep_capture_id'); ?></label>
                    <input type="text" class="form-control" name="hep_capture_id" inputmode="numeric" value="<?php echo (int) $hep['capture_id']; ?>">
                </div>
            </div>

            <div class="u-muted u-fs-11" style="margin:-4px 0 12px;"><?php echo t('ai_echo.hep_single_hint'); ?></div>

            <details style="margin-bottom:14px;border:1px solid var(--border-color);border-radius:8px;padding:10px 12px;" <?php echo $hep['mode'] === 'both' ? 'open' : ''; ?>>
                <summary class="u-strong u-fs-12" style="cursor:pointer;">
                    <i class="fas fa-layer-group"></i> <?php echo t('ai_echo.hep_both_title'); ?>
                </summary>
                <div class="u-muted u-fs-11" style="margin:8px 0 12px;"><?php echo t('ai_echo.hep_both_hint'); ?></div>
                <div class="u-fs-11" style="color:var(--warning);margin-bottom:12px;">
                    <i class="fas fa-microchip"></i> <?php echo t('ai_echo.hep_both_cost'); ?>
                </div>

                <div class="u-strong u-fs-12" style="margin-bottom:6px;">HOMER</div>
                <div class="u-grid-2" style="gap:12px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_echo.hep_host'); ?></label>
                        <input type="text" class="form-control" name="hep_homer_host" value="<?php echo $h($hep['homer_host']); ?>" placeholder="127.0.0.1">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_echo.hep_port'); ?></label>
                        <input type="text" class="form-control" name="hep_homer_port" inputmode="numeric" value="<?php echo (int) $hep['homer_port']; ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label"><?php echo t('ai_echo.hep_ui_url'); ?></label>
                        <input type="url" class="form-control" name="hep_homer_ui_url" value="<?php echo $h($hep['homer_ui_url']); ?>" placeholder="http://127.0.0.1:9080">
                    </div>
                </div>

                <div class="u-strong u-fs-12" style="margin:10px 0 6px;">VoIPmonitor</div>
                <div class="u-grid-2" style="gap:12px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_echo.hep_host'); ?></label>
                        <input type="text" class="form-control" name="hep_voipmonitor_host" value="<?php echo $h($hep['voipmonitor_host']); ?>" placeholder="127.0.0.1">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('ai_echo.hep_port'); ?></label>
                        <input type="text" class="form-control" name="hep_voipmonitor_port" inputmode="numeric" value="<?php echo (int) $hep['voipmonitor_port']; ?>">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label class="form-label"><?php echo t('ai_echo.hep_ui_url'); ?></label>
                        <input type="url" class="form-control" name="hep_voipmonitor_ui_url" value="<?php echo $h($hep['voipmonitor_ui_url']); ?>" placeholder="http://127.0.0.1:8080">
                    </div>
                </div>
            </details>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px;">
                <button type="submit" name="save_hep" value="1" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('ai_echo.hep_apply'); ?></button>
                <button type="submit" name="hep_test" value="1" class="btn btn-secondary"><i class="fas fa-plug-circle-check"></i> <?php echo t('ai_echo.hep_test'); ?></button>
                <?php if ($hep['mode'] === 'both'): ?>
                    <?php foreach ($hep['targets'] as $target): ?>
                        <?php if ($target['ui_url'] !== ''): ?>
                            <a class="btn btn-secondary btn-sm" href="<?php echo $h($target['ui_url']); ?>" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-arrow-up-right-from-square"></i> <?php echo $h($target['label']); ?> UI
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php elseif ($hep['ui_url'] !== ''): ?>
                    <a class="btn btn-secondary btn-sm" href="<?php echo $h($hep['ui_url']); ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-arrow-up-right-from-square"></i> <?php echo t('ai_echo.hep_open_ui'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <div class="u-muted u-fs-12" style="margin-top:14px;border-top:1px solid var(--border-color);padding-top:12px;">
            <?php if ($hep['mode'] === 'both'): ?>
                <div class="u-strong u-fs-12" style="margin-bottom:6px;"><?php echo t('ai_echo.hep_setup_both'); ?></div>
                <div class="u-fs-11" style="margin-bottom:3px;">HOMER:</div>
                <pre style="background:var(--bg-main);padding:10px;border-radius:6px;white-space:pre-wrap;font-size:11px;margin:0 0 8px;"><?php echo $h($homer_cmd); ?></pre>
                <div class="u-fs-11" style="margin-bottom:3px;">VoIPmonitor:</div>
                <pre style="background:var(--bg-main);padding:10px;border-radius:6px;white-space:pre-wrap;font-size:11px;margin:0 0 8px;"><?php echo $h($voipmonitor_cfg); ?></pre>
                <div class="u-muted u-fs-11" style="margin-bottom:8px;"><?php echo t('ai_echo.hep_voipmonitor_license'); ?></div>
            <?php elseif ($hep['mode'] === 'voipmonitor'): ?>
                <div class="u-strong u-fs-12" style="margin-bottom:6px;"><?php echo t('ai_echo.hep_setup_voipmonitor'); ?></div>
                <pre style="background:var(--bg-main);padding:10px;border-radius:6px;white-space:pre-wrap;font-size:11px;margin:0 0 8px;"><?php echo $h($voipmonitor_cfg); ?></pre>
                <div class="u-muted u-fs-11" style="margin-bottom:8px;"><?php echo t('ai_echo.hep_voipmonitor_license'); ?></div>
            <?php elseif ($hep['mode'] === 'homer'): ?>
                <div class="u-strong u-fs-12" style="margin-bottom:6px;"><?php echo t('ai_echo.hep_setup_homer'); ?></div>
                <pre style="background:var(--bg-main);padding:10px;border-radius:6px;white-space:pre-wrap;font-size:11px;margin:0 0 8px;"><?php echo $h($homer_cmd); ?></pre>
            <?php else: ?>
                <div class="u-muted u-fs-11" style="margin-bottom:8px;"><?php echo t('ai_echo.hep_setup_other'); ?></div>
            <?php endif; ?>
            <div style="color: var(--warning);"><i class="fas fa-shield-halved"></i> <?php echo t('ai_echo.hep_warning_external'); ?></div>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-brain u-primary"></i> <?php echo t('ai_echo.oc_title'); ?></div>
        <span class="badge <?php echo !$oc['enabled'] ? 'badge-secondary' : ($oc['available'] ? 'badge-success' : 'badge-warning'); ?> u-fs-11">
            <?php
            echo $h(!$oc['enabled'] ? t('ai_echo.oc_state_off')
                : ($oc['available'] ? t('ai_echo.oc_state_ready') : t('ai_echo.oc_state_missing')));
            ?>
        </span>
    </div>
    <div style="padding: 0 20px 20px;">
        <div class="u-muted u-fs-12" style="margin-bottom: 14px;"><?php echo t('ai_echo.oc_intro'); ?></div>

        <?php if ($oc['enabled'] && !$oc['available']): ?>
            <div class="u-muted u-fs-11" style="margin-bottom: 12px;">
                <i class="fas fa-triangle-exclamation" style="color: var(--warning);"></i>
                <?php echo $h(sprintf(t('ai_echo.oc_missing_hint'), $oc['bin'])); ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="margin-bottom: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="u-grid-2" style="gap: 16px; align-items: end;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('ai_echo.oc_model'); ?></label>
                    <input type="text" class="form-control" name="opencode_model"
                           value="<?php echo $h($oc['model']); ?>" placeholder="opencode/big-pickle">
                </div>
                <div class="form-group" style="display:flex;gap:10px;align-items:center;">
                    <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                        <input type="checkbox" name="opencode_enabled" value="1" <?php echo $oc['enabled'] ? 'checked' : ''; ?>>
                        <span class="u-fs-12"><?php echo t('ai_echo.oc_enabled'); ?></span>
                    </label>
                    <button type="submit" name="save_opencode" value="1" class="btn btn-secondary">
                        <i class="fas fa-save"></i> <?php echo t('ai_echo.oc_save'); ?>
                    </button>
                </div>
            </div>
        </form>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <div class="form-group">
                <label class="form-label"><?php echo t('ai_echo.oc_task'); ?></label>
                <textarea class="form-control" name="opencode_task" rows="2" maxlength="2000"
                          placeholder="<?php echo $h(t('ai_echo.oc_placeholder')); ?>"></textarea>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <select class="form-control" name="opencode_kind" style="max-width:220px;">
                    <option value="tool"><?php echo t('ai_echo.oc_kind_tool'); ?></option>
                    <option value="research"><?php echo t('ai_echo.oc_kind_research'); ?></option>
                </select>
                <button type="submit" name="propose_opencode" value="1" class="btn btn-primary"
                        <?php echo $oc['enabled'] && $oc['available'] ? '' : 'disabled'; ?>>
                    <i class="fas fa-flask"></i> <?php echo t('ai_echo.oc_run'); ?>
                </button>
                <span class="u-muted u-fs-11"><?php echo t('ai_echo.oc_running'); ?></span>
            </div>
        </form>

        <?php if (isset($last_run['proposal'])): ?>
            <pre style="background:var(--bg-main);padding:12px;border-radius:6px;white-space:pre-wrap;font-size:12px;margin:16px 0 0;max-height:340px;overflow:auto;"><?php echo $h($last_run['proposal']['output']); ?></pre>
        <?php endif; ?>

        <div style="margin-top:16px;border-top:1px solid var(--border-color);padding-top:12px;">
            <div class="u-strong u-fs-12" style="margin-bottom:8px;"><?php echo t('ai_echo.oc_recent'); ?></div>
            <?php if (!$oc_recent): ?>
                <div class="u-muted u-fs-12"><?php echo t('ai_echo.oc_no_runs'); ?></div>
            <?php else: ?>
                <table class="table" style="width:100%;font-size:12px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php echo t('ai_echo.oc_kind'); ?></th>
                            <th><?php echo t('ai_echo.oc_task'); ?></th>
                            <th><?php echo t('ai_echo.oc_status'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($oc_recent as $p): ?>
                            <tr>
                                <td>#<?php echo (int) $p['id']; ?></td>
                                <td><?php echo $h($p['kind'] === 'research' ? t('ai_echo.oc_kind_research') : t('ai_echo.oc_kind_tool')); ?></td>
                                <td>
                                    <details>
                                        <summary style="cursor:pointer;"><?php echo $h(mb_substr($p['task'], 0, 90)); ?></summary>
                                        <pre style="background:var(--bg-main);padding:10px;border-radius:6px;white-space:pre-wrap;font-size:11px;margin:8px 0 0;"><?php echo $h($p['output']); ?></pre>
                                    </details>
                                </td>
                                <td>
                                    <span class="badge <?php echo $p['status'] === 'approved' ? 'badge-success' : ($p['status'] === 'rejected' ? 'badge-danger' : 'badge-secondary'); ?> u-fs-11">
                                        <?php echo t('ai_echo.oc_status_' . $p['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <form method="POST" style="display:flex;gap:6px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                                            <input type="hidden" name="proposal_id" value="<?php echo (int) $p['id']; ?>">
                                            <button type="submit" name="decide_proposal" value="approve" class="btn btn-secondary" style="padding:2px 8px;font-size:11px;"><?php echo t('ai_echo.oc_approve'); ?></button>
                                            <button type="submit" name="decide_proposal" value="reject" class="btn btn-secondary" style="padding:2px 8px;font-size:11px;"><?php echo t('ai_echo.oc_reject'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <div class="u-muted u-fs-11" style="margin-top:10px;">
                <i class="fas fa-lock"></i> <?php echo t('ai_echo.oc_decision_note'); ?>
            </div>
        </div>
    </div>
</div>
