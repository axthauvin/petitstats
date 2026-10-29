<!-- Page: Websites Management (Inspired by Image 1 & Standalone Portal) -->
<div class="page-view" id="page-websites">
    <!-- Standalone Portal Header with Petitstats Brand Logo -->
    <div class="portal-header">
        <div class="portal-brand-block">
            <div class="brand-logo-emblem">
                <img src="/dashboard/src/assets/logo.svg" alt="Petitstats" width="36" height="36" class="portal-brand-logo-img">
                <div style="margin-left: 2px;">
                    <div class="portal-brand-text">Petitstats</div>
                    <div class="portal-subtitle">Websites & Projects</div>
                </div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="#settings" class="btn-outline">Settings</a>
            <button type="button" class="btn-outline" id="btnWebsitesImport" title="Import from Umami, Google Analytics, Plausible...">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Import Data
            </button>
            <button type="button" class="btn-primary" id="btnWebsitesAddSite">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add New Website
            </button>
        </div>
    </div>

    <!-- Quick Global KPIs Grid -->
    <div class="websites-kpi-grid">
        <div class="kpi-card">
            <span class="kpi-label">Configured Websites</span>
            <div class="kpi-value-row">
                <span class="kpi-number" id="globalTotalSites">0</span>
            </div>
            <span class="kpi-hint">Isolated analytics storage</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Total Recorded Views</span>
            <div class="kpi-value-row">
                <span class="kpi-number" id="globalTotalViews">0</span>
            </div>
            <span class="kpi-hint">Aggregated across all targets</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Live Active Visitors</span>
            <div class="kpi-value-row">
                <span class="kpi-number" id="globalLiveVisitors">0 active</span>
                <span class="pulse-dot"></span>
            </div>
            <span class="kpi-hint">Active in the last 5 minutes</span>
        </div>
    </div>

    <!-- Search & Filter Bar (Matching Image 1) -->
    <div class="filter-toolbar">
        <div class="search-input-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" class="search-input" id="websiteSearchInput" placeholder="Search...">
        </div>
    </div>

    <!-- Websites Table Card (Matching Image 1) -->
    <div class="data-table-card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 280px;">Name <span>↕</span></th>
                        <th>Domain <span>↕</span></th>
                        <th style="width: 170px;">Visitors (7d)</th>
                        <th style="width: 160px;">Created <span>↕</span></th>
                        <th style="width: 140px; text-align: right;"></th>
                    </tr>
                </thead>
                <tbody id="websitesTableBody">
                    <tr>
                        <td colspan="5" class="empty-state">Loading websites...</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="table-records-footer" id="websitesRecordsCount">
            0 records
        </div>
    </div>
</div>
