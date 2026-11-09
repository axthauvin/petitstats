<!-- Page: User Sessions -->
<div class="page-view" id="page-sessions">
    <!-- View 1: Sessions List View -->
    <div id="sessionsListView">
        <div class="page-header-box">
            <div class="page-intro">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h1>Sessions</h1>
                </div>
                <p>Recorded user sessions with real device, browser, country, and chronological actions.</p>
            </div>
        </div>

        <!-- Active filters coming from the Overview -->
        <div class="active-filters-bar" id="sessionsFilterBar" hidden></div>

        <!-- Filter & Search Toolbar -->
        <div class="filter-toolbar" style="margin-bottom: 20px;">
            <div class="search-input-wrapper">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" class="search-input" id="sessionSearch" placeholder="Search sessions by ID, path, country...">
            </div>

            <!-- Date Filter (Specific day) -->
            <div class="date-filter-wrapper">
                <svg class="date-filter-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <input type="text" class="date-filter-input calendar-input" id="sessionDateFilter" title="Filter by specific day" placeholder="Select a day" readonly>
                <button type="button" class="btn-clear-date" id="clearDateFilterBtn" title="Clear day filter" style="display: none;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <!-- Event Filter (Specific event) -->
            <div class="event-filter-wrapper">
                <select class="select-filter" id="sessionEventFilter" title="Filter by event">
                    <option value="all">All events</option>
                </select>
            </div>

            <!-- Total Sessions Count Badge -->
            <div class="sessions-count-wrapper" style="margin-left: auto;">
                <span id="sessionsTotalCount" class="sessions-count-badge">
                    0 sessions
                </span>
            </div>
        </div>

        <!-- Sessions Cards List -->
        <div class="sessions-cards-container" id="sessionsListContainer">
            <div class="data-table-card" style="padding: 32px; text-align: center; color: var(--text-muted);">
                Loading sessions...
            </div>
        </div>

        <!-- Pagination Controls -->
        <div class="pagination-footer" style="margin-top: 16px; background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md);">
            <span id="sessionsPageInfo">Page 1 of 1</span>
            <div class="pagination-controls">
                <button type="button" class="btn-page" id="sessionsPrevPage">Previous</button>
                <button type="button" class="btn-page" id="sessionsNextPage">Next</button>
            </div>
        </div>
    </div>

    <!-- View 2: Session Detail Drill-down View (Inspired by User Screenshot) -->
    <div id="sessionDetailView" style="display: none;">
        <!-- Back Button Header -->
        <div style="margin-bottom: 20px;">
            <button type="button" class="btn-back-sessions-list" id="btnBackToSessionsList">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
                <span>Back to sessions</span>
            </button>
            <button type="button" class="btn-delete-session" id="btnDeleteSession">Delete visitor</button>
        </div>

        <!-- 2-Column Inspection Grid -->
        <div class="session-detail-grid">
            <!-- Left Column: Page Journey -->
            <div class="session-journey-card">
                <div class="journey-header">
                    <h3 class="journey-title">Page journey</h3>
                    <div class="journey-controls">
                        <label class="journey-search" for="sessionEventSearch">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="search" id="sessionEventSearch" placeholder="Search pages and events" autocomplete="off">
                        </label>
                        <button type="button" class="btn-journey-order" id="sessionEventOrder" aria-pressed="true" title="Show oldest events first">
                            <span id="sessionEventOrderLabel">Newest first</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m3 16 4 4 4-4"></path>
                                <path d="M7 20V4"></path>
                                <path d="m21 8-4-4-4 4"></path>
                                <path d="M17 4v16"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="journey-steps-container" id="journeyStepsList">
                    <!-- Populated dynamically by SessionsPage.renderDetailView -->
                </div>
            </div>

            <!-- Right Column: Session information and context -->
            <div class="session-meta-sidebar-card">
                <div class="session-meta-top-box">
                    <div class="session-identity">
                        <div class="session-detail-avatar-wrap">
                            <img id="detailAvatarImg" src="" alt="Avatar" class="session-detail-avatar-img">
                            <span class="session-status-dot"></span>
                        </div>
                        <div class="session-identity-text">
                            <span class="session-meta-id-title font-mono" id="detailSessionId">...</span>
                            <div class="session-meta-geo-row" id="detailGeoRow">
                                <span id="detailCountryFlag">🌐</span>
                                <span id="detailCountryName">Unknown</span>
                            </div>
                            <div class="session-meta-tech-row" id="detailTechRow"></div>
                        </div>
                    </div>
                </div>

                <div class="session-summary-grid">
                    <div class="session-summary-card">
                        <span class="session-summary-label">Visits</span>
                        <strong class="session-summary-value" id="detailVisitCount">1</strong>
                    </div>
                    <div class="session-summary-card">
                        <span class="session-summary-label">Time on site</span>
                        <strong class="session-summary-value" id="detailDuration">0s</strong>
                    </div>
                    <div class="session-summary-card">
                        <span class="session-summary-label">Page views</span>
                        <strong class="session-summary-value" id="detailPageviews">0</strong>
                    </div>
                    <div class="session-summary-card">
                        <span class="session-summary-label">Events</span>
                        <strong class="session-summary-value" id="detailEventCount">0</strong>
                    </div>
                </div>

                <div class="session-meta-section">
                    <div class="meta-field-row">
                        <span class="meta-field-label">Source</span>
                        <span class="meta-field-val" id="detailSource">Direct / None</span>
                    </div>
                    <div class="meta-field-row">
                        <span class="meta-field-label">First seen</span>
                        <span class="meta-field-val" id="detailStartedAt">–</span>
                    </div>
                    <div class="meta-field-row">
                        <span class="meta-field-label">Last seen</span>
                        <span class="meta-field-val" id="detailLastActivity">–</span>
                    </div>
                </div>

                <div class="session-meta-section">
                    <h4 class="meta-section-title">Device and environment</h4>
                    <div class="meta-field-row">
                        <span class="meta-field-label">Device type</span>
                        <span class="meta-field-val" id="detailDevice">Desktop</span>
                    </div>
                    <div class="meta-field-row">
                        <span class="meta-field-label">Screen</span>
                        <span class="meta-field-val" id="detailScreen">–</span>
                    </div>
                    <div class="meta-field-row">
                        <span class="meta-field-label">Viewport</span>
                        <span class="meta-field-val" id="detailViewport">–</span>
                    </div>
                    <div class="meta-field-row">
                        <span class="meta-field-label">Language</span>
                        <span class="meta-field-val" id="detailLocale">en</span>
                    </div>
                    <p class="session-consent-notice" id="detailConsentNotice" hidden></p>
                </div>
            </div>
        </div>
    </div>
</div>
