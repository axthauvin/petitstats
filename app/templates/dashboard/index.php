<?php
/**
 * @var array<string, mixed>|null $authUser
 * @var bool $isGuest
 * @var bool $isEmbed
 * @var array<string, mixed>|null $publicSite Set for guests browsing the live demo
 */

use Petitstats\Auth\Csrf;
use Petitstats\Http\Asset;
use Petitstats\Http\Docs;
use Petitstats\Http\View;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="/dashboard/">
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <title>Petitstats | Privacy-Friendly Web Analytics</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/css/flag-icons.min.css">
    <!-- Core Stylesheets -->
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/overview.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/acquisition.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/revenue.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/site-setup.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/settings.css') ?>">
    <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/funnels.css') ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="/favicon.png">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/favicon.png">
    <script>
        window.PETITSTATS_GUEST = <?php echo $isGuest ? 'true' : 'false'; ?>;
        window.PETITSTATS_EMBED = <?php echo $isEmbed ? 'true' : 'false'; ?>;
        window.PETITSTATS_ADMIN = <?php echo ($authUser['role'] ?? '') === 'admin' ? 'true' : 'false'; ?>;
        window.PETITSTATS_PUBLIC_SITE = <?php echo $isGuest ? json_encode($publicSite['id']) : 'null'; ?>;
    </script>
    <script>
        // Same rule as App.bindSidebarCollapse(), applied before the first paint.
        (function() {
            var collapsed = false;
            try {
                collapsed = localStorage.getItem('petitstats_sidebar_collapsed') === 'true';
            } catch (e) {}
            if (window.matchMedia('(min-width: 768px) and (max-width: 1099px)').matches
                || (collapsed && window.matchMedia('(min-width: 1100px)').matches)) {
                document.documentElement.classList.add('nav-compact');
            }
        })();
    </script>
</head>
<body class="<?php echo trim(($isGuest ? 'guest-mode ' : '') . ($isEmbed ? 'embed-mode' : '')); ?>">
    <!-- Top global loading bar -->
    <div id="appLoadingBar" class="app-loading-bar" aria-hidden="true"></div>

    <div class="app-container">
        <!-- Left Sidebar Navigation -->
        <?php include View::path('dashboard/sidebar'); ?>
        <div class="mobile-nav-backdrop" id="mobileNavBackdrop" aria-hidden="true"></div>

        <!-- Main Content Area -->
        <div class="main-wrapper">
            <?php if ($isGuest): ?>
            <!-- Live demo banner (read-only guest access) -->
            <div class="demo-banner" role="note">
                <?php if ($isEmbed): ?>
                <nav class="demo-embed-tabs" aria-label="Demo sections">
                    <?php foreach (['overview' => 'Overview', 'acquisition' => 'Acquisition', 'sessions' => 'Sessions', 'events' => 'Events', 'funnels' => 'Funnels', 'revenue' => 'Revenue'] as $page => $label): ?>
                    <a href="#<?php echo $page; ?>" class="nav-item" data-page="<?php echo $page; ?>"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </nav>
                <a href="/dashboard/?site=<?php echo urlencode($publicSite['id']); ?>#overview" target="_blank" rel="noopener">Open full demo ↗</a>
                <?php else: ?>
                <span><strong>Live demo</strong> · read-only mock data.</span>
                <a href="/install.html">Get Petitstats</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (($authUser['role'] ?? '') === 'admin'): ?>
            <!-- Shown by App.checkServerConfig() when the .htaccess rules are not applied -->
            <div class="server-config-banner" id="serverConfigWarning" role="alert" hidden>
                <span><strong>Your web server is not applying the Petitstats access rules:</strong> application files such as <code>composer.json</code> are publicly readable.</span>
                <a href="<?= Docs::url('operations', 'web-server-configuration') ?>" target="_blank" rel="noopener">Nginx &amp; Caddy setup ↗</a>
            </div>
            <?php endif; ?>
            <?php if (!$isGuest): ?>
            <!-- Shown by the API client when the session ends or the page's CSRF token is stale -->
            <div class="session-banner" id="sessionBanner" role="alert" hidden>
                <span></span>
                <a href="/dashboard/login.php"></a>
            </div>
            <?php endif; ?>
            <!-- Top Header with Live Visitors & Controls -->
            <?php include View::path('dashboard/header'); ?>

            <!-- Page Views Container -->
            <main class="content-container">
                <!-- Page 0: Websites Management (Home) -->
                <?php include View::path('dashboard/pages/websites'); ?>

                <!-- Page 1: Overview & Views -->
                <?php include View::path('dashboard/pages/overview'); ?>
                <?php include View::path('dashboard/pages/acquisition'); ?>

                <!-- Page 2: Filterable Events Stream -->
                <?php include View::path('dashboard/pages/events'); ?>

                <!-- Page 3: User Sessions -->
                <?php include View::path('dashboard/pages/sessions'); ?>
                <?php include View::path('dashboard/pages/funnels'); ?>
                <?php include View::path('dashboard/pages/revenue'); ?>
                <?php include View::path('dashboard/pages/settings'); ?>
            </main>
            <p class="app-footnote">This product includes GeoLite2 data created by MaxMind, available from <a href="https://www.maxmind.com" target="_blank" rel="noopener noreferrer">https://www.maxmind.com</a></p>
        </div>
    </div>

    <!-- Inspector Modals & Drawers -->
    <?php include View::path('dashboard/modals/payload'); ?>
    <?php include View::path('dashboard/modals/session-detail'); ?>
    <?php include View::path('dashboard/modals/add-site'); ?>
    <?php include View::path('dashboard/modals/whats-new'); ?>

    <!-- Lucide Icons & Icon Helper -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/icons.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/time-format.js') ?>"></script>

    <!-- Modular Application Scripts -->
    <script src="<?= Asset::url('/dashboard/src/assets/js/api.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/filters.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/calendar-picker.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/vendor/echarts/echarts.common.min.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/chart.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/websites.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/site-setup.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/overview.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/acquisition.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/events.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/sessions.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/funnels.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/revenue.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/changelog.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/settings.js') ?>"></script>
    <script src="<?= Asset::url('/dashboard/src/assets/js/app.js') ?>"></script>
</body>
</html>
