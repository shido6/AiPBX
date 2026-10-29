<?php
/**
 * Header Topbar & Quick Controls Component
 */
?>
<header class="top-bar">
    <div class="top-bar-left">
<?php
$is_collapsed_cookie = $is_collapsed_cookie ?? (isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === 'true');
?>
        <button type="button" class="sidebar-toggle-btn" id="header-sidebar-toggle" onclick="toggleSidebar(event)" title="<?php echo t('sidebar.toggle_tooltip'); ?>">
            <i class="fas <?php echo $is_collapsed_cookie ? 'fa-chevron-right' : 'fa-chevron-left'; ?>" id="header-sidebar-toggle-icon"></i>
        </button>

        <!-- Live Phone Status Badge (clicking opens the phone settings modal) -->
        <div class="header-phone-status-pill" id="header-phone-status-pill" title="<?php echo t('topbar.phone_status_tooltip'); ?>" onclick="openPhoneSettingsModal()">
            <span class="status-dot" id="header-status-dot"></span>
            <span class="status-text" id="header-status-text"><?php echo htmlspecialchars($user['extension'] ?: '3000'); ?></span>
        </div>


        <!-- Header Break Selection Dropdown (STRICTLY FOR CALL CENTER AGENTS) -->
        <?php if (!empty($is_cc_agent)): ?>
            <div class="header-break-wrapper" style="flex-shrink: 0;">
                <select id="header-break-select" onchange="handleHeaderBreakChange(this.value)" class="form-control" style="padding: 2px 8px; font-size: 11px; font-weight: 600; height: 26px; border-radius: 13px; background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-main); cursor: pointer;" title="<?php echo t('topbar.break_selector_tooltip'); ?>">
                    <option value="" class="u-success u-fw-700"><?php echo t('topbar.break_working'); ?></option>
                    <?php foreach (array_filter(array_map('trim', explode(',', getSystemSetting('cc_break_reasons', t('api_cc.default_break_reasons'))))) as $reason): ?>
                        <option value="<?php echo htmlspecialchars($reason); ?>">⏸️ <?php echo htmlspecialchars($reason); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <!-- Quick Phone Call & Dynamic Call Control Group -->
        <div class="header-quick-dial-wrapper" id="header-phone-controls">
            <!-- 1. IDLE / READY STATE CONTROLS -->
            <div id="header-idle-controls" style="display: flex; align-items: center; gap: 6px;">
                <!-- Quick Dial Input & Autocomplete Dropdown -->
                <input type="text" id="header-quick-dial-input" class="header-quick-dial-input" placeholder="<?php echo t('topbar.quick_dial_placeholder'); ?>" autocomplete="off" onkeydown="handleHeaderQuickDialKeydown(event)" oninput="handleHeaderQuickDialInput(this.value)">
                <div class="header-autocomplete-dropdown" id="header-autocomplete-dropdown" style="display: none;"></div>
                <button type="button" id="header-video-call-btn" class="topbar-btn btn-primary" onclick="headerQuickDialCall(true)" title="<?php echo t('topbar.video_call_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i>
                </button>
            </div>

            <!-- 2. INCOMING CALL STATE CONTROLS -->
            <div id="header-incoming-controls" style="display: none; align-items: center; gap: 6px;">
                <button type="button" class="topbar-btn btn-success header-btn-pulse" onclick="headerPhoneAnswerCall()" title="<?php echo t('topbar.answer_tooltip'); ?>">
                    <i class="fas fa-phone"></i> <span class="topbar-btn-label"><?php echo t('topbar.answer_label'); ?></span>
                </button>
                <button type="button" id="header-answer-video-btn" class="topbar-btn btn-primary header-btn-pulse" onclick="headerPhoneAnswerCall(true)" title="<?php echo t('topbar.answer_video_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i> <span class="topbar-btn-label"><?php echo t('topbar.answer_video_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-danger" onclick="headerPhoneRejectCall()" title="<?php echo t('topbar.reject_tooltip'); ?>">
                    <i class="fas fa-phone-slash"></i> <span class="topbar-btn-label"><?php echo t('topbar.reject_label'); ?></span>
                </button>
            </div>

            <!-- 3. ACTIVE CALL / DIALING STATE CONTROLS -->
            <div id="header-active-controls" style="display: none; align-items: center; gap: 6px;">
                <button type="button" class="topbar-btn" onclick="toggleHeaderSoftphoneDrawer()" title="<?php echo t('topbar.dtmf_tooltip'); ?>">
                    <i class="fas fa-th"></i>
                </button>
                <button type="button" id="header-camera-btn" class="topbar-btn" onclick="headerPhoneToggleCamera()" title="<?php echo t('topbar.camera_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i>
                </button>
                <button type="button" id="header-hold-btn" class="topbar-btn btn-warning" onclick="headerPhoneToggleHold()" title="<?php echo t('topbar.hold_tooltip'); ?>">
                    <i class="fas fa-pause"></i> <span id="header-hold-text" class="topbar-btn-label"><?php echo t('topbar.hold_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-primary" onclick="headerPhonePromptTransfer()" title="<?php echo t('topbar.transfer_tooltip'); ?>">
                    <i class="fas fa-exchange-alt"></i> <span class="topbar-btn-label"><?php echo t('topbar.transfer_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-danger" onclick="headerPhoneHangup()" title="<?php echo t('topbar.hangup_tooltip'); ?>">
                    <i class="fas fa-phone-slash"></i> <span class="topbar-btn-label"><?php echo t('topbar.hangup_label'); ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Top Bar Right: Pending Sync (Uygula) Dropdown & Actions -->
    <div class="top-bar-right">
        <?php if (($_SESSION['user_role'] ?? '') === 'admin'): /* api/notices.php checks it too */ ?>
            <div class="header-notices-wrapper" id="header-notices">
                <button type="button" class="topbar-btn header-notices-btn" id="header-notices-btn" onclick="toggleNotices(event)"
                        title="<?php echo htmlspecialchars(t('notices.title')); ?>" aria-label="<?php echo htmlspecialchars(t('notices.title')); ?>" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <span class="header-notices-count" id="header-notices-count" hidden>0</span>
                </button>
                <div class="header-sync-dropdown header-notices-dropdown" id="header-notices-dropdown" role="dialog" aria-label="<?php echo htmlspecialchars(t('notices.title')); ?>">
                    <div class="header-sync-dropdown-title"><i class="fas fa-bell"></i> <?php echo htmlspecialchars(t('notices.title')); ?></div>
                    <div class="header-notices-list" id="header-notices-list"><div class="header-notices-empty"><?php echo htmlspecialchars(t('notices.loading')); ?></div></div>
                    <a href="/health" class="header-notices-foot" onclick="closeNotices()"><?php echo htmlspecialchars(t('notices.all_checks')); ?> →</a>
                </div>
            </div>
        <?php endif; ?>
        <?php
        if (!isset($pending_sync_count)) {
            require_once __DIR__ . '/../src/asterisk_sync.php';
            $pending_sync_count = hasModulePermission('pending_sync', 'view') ? getPendingSyncCount() : 0;
        }
        ?>
        <?php if (hasModulePermission('pending_sync', 'view')): ?>
            <div class="header-sync-wrapper" id="header-pending-sync-container" style="display: <?php echo $pending_sync_count > 0 ? 'inline-flex' : 'none'; ?>;">
                <button type="button" class="btn header-sync-btn" id="header-sync-btn" onclick="toggleHeaderSyncDropdown(event)" title="<?php echo t('sidebar.pending_sync_tooltip'); ?>">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span class="header-sync-label"><?php echo t('sidebar.pending_sync_label'); ?></span>
                    <span class="header-sync-count" id="header-pending-sync-count"><?php echo $pending_sync_count; ?></span>
                    <i class="fas fa-chevron-down header-sync-arrow"></i>
                </button>

                <div class="header-sync-dropdown" id="headerSyncDropdown">
                    <div class="header-sync-dropdown-title">
                        <i class="fas fa-tasks"></i> <?php echo t('topbar.pending_changes_title'); ?> (<span id="header-sync-dropdown-count"><?php echo $pending_sync_count; ?></span>)
                    </div>
                    
                    <?php if (hasModulePermission('pending_sync', 'edit')): ?>
                    <button type="button" class="header-sync-dropdown-item" onclick="executeDirectPendingSync(event)">
                        <div class="sync-item-icon sync-item-icon-direct">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="sync-item-text">
                            <div class="sync-item-title"><?php echo t('topbar.direct_apply'); ?></div>
                            <div class="sync-item-desc"><?php echo t('topbar.direct_apply_desc'); ?></div>
                        </div>
                    </button>
                    <?php endif; ?>

                    <a href="/pending-sync" class="header-sync-dropdown-item" onclick="closeHeaderSyncDropdown()">
                        <div class="sync-item-icon sync-item-icon-review">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div class="sync-item-text">
                            <div class="sync-item-title"><?php echo t('topbar.review_apply'); ?></div>
                            <div class="sync-item-desc"><?php echo t('topbar.review_apply_desc'); ?></div>
                        </div>
                    </a>
                </div>
            </div>
        <?php endif; ?>
        <?php
        // Greg — the AI VoIP Engineer entity: avatar top-right (the logo
        // sits top-left), token counter beside it, notices panel under it.
        require_once __DIR__ . '/../src/services/GregService.php';
        if (GregService::avatarVisible()):
            $greg_s = GregService::settings();
            $greg_unread = GregService::unreadCount();
            $greg_notices = GregService::recentNotices();
        ?>
        <div class="greg-widget" id="greg-widget">
            <?php if ($greg_s['show_tokens']): ?>
                <span class="greg-token-chip" id="greg-token-chip" title="<?php echo htmlspecialchars(t('ai_voip_engineer.month_tokens')); ?>">
                    <i class="fas fa-bolt"></i>&nbsp;<span id="greg-token-count"><?php echo number_format(GregService::monthTokens()); ?></span>
                </span>
            <?php endif; ?>
            <button type="button" class="greg-avatar-btn" id="greg-avatar-btn"
                    onclick="gregTogglePanel()" title="<?php echo htmlspecialchars($greg_s['name']); ?>"
                    aria-label="<?php echo htmlspecialchars($greg_s['name']); ?>">
                <span class="greg-avatar-face" aria-hidden="true">&#127913;</span>
                <!-- Always present: the panel's mark-read script looks it up. A null
                     lookup is a smoke failure and a silent JS stop, even though the
                     script guards it; hidden when there is nothing to show. -->
                <span class="greg-badge" id="greg-unread-badge"<?php echo $greg_unread > 0 ? '' : ' style="display: none;"'; ?>><?php echo $greg_unread; ?></span>
            </button>
            <div class="greg-panel" id="greg-panel" style="display: none;">
                <div class="greg-panel-head">
                    <span class="greg-avatar-face greg-avatar-face-sm" aria-hidden="true">&#127913;</span>
                    <span class="u-strong u-fs-13" id="greg-panel-name"><?php echo htmlspecialchars($greg_s['name']); ?></span>
                    <span class="u-muted u-fs-11">(VoIPMonk)</span>
                    <a class="u-fs-11" href="/chat" style="margin-left: auto;"><i class="fas fa-comments"></i> <?php echo htmlspecialchars(t('greg.panel_chat')); ?></a>
                </div>
                <div class="greg-panel-scroll" id="greg-panel-scroll">
                    <?php if (!$greg_notices): ?>
                        <div class="greg-notice-empty"><?php echo htmlspecialchars(t('greg.panel_no_notices')); ?></div>
                    <?php endif; ?>
                    <?php foreach ($greg_notices as $n): ?>
                        <div class="greg-notice greg-sev-<?php echo htmlspecialchars($n['severity']); ?><?php echo $n['read_at'] ? '' : ' greg-notice-unread'; ?>">
                            <div class="greg-notice-head">
                                <span class="badge u-fs-10 greg-sev-badge"><?php echo htmlspecialchars(t('ai_voip_engineer.sev_' . $n['severity'])); ?></span>
                                <span class="u-muted u-fs-10"><?php echo htmlspecialchars(date('d.m H:i', strtotime($n['created_at']))); ?></span>
                                <?php if ($n['emailed_at']): ?><i class="fas fa-envelope u-muted u-fs-10" title="e-mailed"></i><?php endif; ?>
                            </div>
                            <div class="greg-notice-title"><?php echo htmlspecialchars($n['title']); ?></div>
                            <div class="greg-notice-body"><?php echo htmlspecialchars(mb_substr((string) $n['body'], 0, 260)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="greg-panel-foot">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="gregMarkRead()"><i class="fas fa-check"></i> <?php echo htmlspecialchars(t('greg.panel_mark_read')); ?></button>
                    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
                        <a class="u-fs-11" href="/ai-voip-engineer"><i class="fas fa-sliders"></i> <?php echo htmlspecialchars(t('greg.panel_settings')); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        var GREG_CSRF = "<?php echo htmlspecialchars(getCSRFToken()); ?>";
        function gregTogglePanel() {
            var panel = document.getElementById('greg-panel');
            if (!panel) return;
            var show = panel.style.display === 'none';
            panel.style.display = show ? 'block' : 'none';
            if (show) gregMarkRead(true);
        }
        function gregMarkRead(quiet) {
            var badge = document.getElementById('greg-unread-badge');
            var body = new URLSearchParams({ action: 'mark_read', csrf_token: GREG_CSRF });
            fetch('/api/greg.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function () { if (badge) badge.remove(); })
                .catch(function () {});
        }
        </script>
        <?php endif; ?>
    </div>
</header>
