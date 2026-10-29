<!-- Sleek Gray Sidebar (Minimalist with Text Labels & Back Navigation) -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <div class="sidebar-header-row">
            <a href="<?php echo !empty($isGuest) ? '/' : '#websites'; ?>" class="sidebar-brand-link" id="sidebarBrandLink" title="<?php echo !empty($isGuest) ? 'Petitstats | Home' : 'Petitstats | All Websites'; ?>">
                <img src="/dashboard/src/assets/logo.svg" class="sidebar-brand-icon" width="28" height="28" alt="Petitstats Logo">
                <span class="sidebar-brand-text">Petitstats</span>
            </a>
            <button type="button" class="btn-sidebar-collapse" id="btnSidebarCollapse" title="Collapse navigation (Ctrl+B)" aria-label="Collapse navigation" aria-controls="sidebar" aria-expanded="true">
                <svg class="icon-collapse-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </button>
        </div>

        <?php if (empty($isGuest)): ?>
        <!-- Back to websites button (Primary navigation out of individual site) -->
        <a href="#websites" class="btn-back-websites" id="btnBackToWebsites" title="Back to All Websites">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            <span>All Websites</span>
        </a>
        <?php endif; ?>

        <!-- Current Active Website Info Card -->
        <div class="sidebar-site-box" id="sidebarSiteBox" title="Current Website Analytics">
            <div class="site-avatar" id="sidebarSiteAvatar">
                <img id="sidebarSiteLogo" class="site-avatar-img" src="" alt="" style="display: none;" />
                <span id="sidebarSiteFallback" class="site-avatar-fallback">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path>
                        <path d="M2 12h20"></path>
                    </svg>
                </span>
            </div>
            <div class="site-meta">
                <div class="site-name" id="sidebarSiteName">No website selected</div>
                <div class="site-sub">
                    <span class="live-dot-green"></span>
                    <span id="sidebarSiteDomain">Select a website to view analytics</span>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Website analytics">
            <h2 class="nav-section-label" id="navLabelAnalytics">Analytics</h2>
            <ul class="nav-list" aria-labelledby="navLabelAnalytics">
                <!-- 1. Overview -->
                <li class="nav-item active" data-page="overview">
                    <a href="#overview">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="7" height="7"></rect>
                            <rect x="14" y="3" width="7" height="7"></rect>
                            <rect x="14" y="14" width="7" height="7"></rect>
                            <rect x="3" y="14" width="7" height="7"></rect>
                        </svg>
                        <span class="nav-title">Overview</span>
                    </a>
                </li>
                <li class="nav-item" data-page="acquisition">
                    <a href="#acquisition">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"></path><path d="m7 16 4-5 3 3 5-7"></path></svg>
                        <span class="nav-title">Acquisition</span>
                    </a>
                </li>

                <!-- 2. Sessions -->
                <li class="nav-item" data-page="sessions">
                    <a href="#sessions">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span class="nav-title">Sessions</span>
                    </a>
                </li>

                <!-- 3. Events -->
                <li class="nav-item" data-page="events">
                    <a href="#events">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span class="nav-title">Events</span>
                    </a>
                </li>
                <li class="nav-item" data-page="funnels">
                    <a href="#funnels">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 4h18l-7 8v6l-4 2v-8z"></path>
                        </svg>
                        <span class="nav-title">Funnels</span>
                    </a>
                </li>
                <li class="nav-item" data-page="revenue">
                    <a href="#revenue">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="6" width="20" height="12" rx="2"></rect>
                            <circle cx="12" cy="12" r="2"></circle>
                            <path d="M6 12h.01M18 12h.01"></path>
                        </svg>
                        <span class="nav-title">Revenue</span>
                    </a>
                </li>
            </ul>

            <?php if (empty($isGuest)): ?>
            <h2 class="nav-section-label" id="navLabelIngestion">Data Ingestion</h2>
            <ul class="nav-list" aria-labelledby="navLabelIngestion">
                <li class="nav-item">
                    <button type="button" id="btnSidebarImport" title="Import from Umami, GA4, Plausible...">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                        <span class="nav-title">Import Data</span>
                    </button>
                </li>
            </ul>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Bottom Actions -->
    <div class="sidebar-footer">
        <?php if (!empty($isGuest)): ?>
        <!-- Live demo: read-only access, no account -->
        <div class="demo-mode-card">
            <div class="demo-mode-badge"><span class="pulse-dot"></span><span>Demo Mode</span></div>
            <p class="demo-mode-text">Read-only mock data. Settings and imports are disabled.</p>
            <a class="demo-mode-cta" href="/install.html" target="_top">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /><polyline points="7 10 12 15 17 10" /><line x1="12" y1="15" x2="12" y2="3" /></svg>
                <span>Get Petitstats</span>
            </a>
        </div>
        <?php else: ?>
        <a href="#settings" class="footer-link nav-item" data-page="settings" title="Manage users and access">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" />
                <circle cx="12" cy="12" r="3" />
            </svg>
            <span>Settings</span>
            <span class="update-dot" data-update-dot title="A new Petitstats version is available" hidden></span>
        </a>

        <div class="sidebar-user-row">
            <div class="user-avatar"><?php $authUser = \Petitstats\Auth\Auth::user();
            echo htmlspecialchars(strtoupper(substr($authUser['email'] ?? 'A', 0, 1))); ?></div>
            <div class="user-info">
                <span class="user-name" title="<?php echo htmlspecialchars($authUser['email'] ?? 'Admin'); ?>"><?php echo htmlspecialchars($authUser['email'] ?? 'Admin'); ?></span>
                <a class="user-role" href="/dashboard/logout.php">Sign out</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</aside>
