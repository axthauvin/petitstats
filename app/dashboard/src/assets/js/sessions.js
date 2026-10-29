/**
 * Petitstats Sessions Page Controller
 * Uses Lucide icons (no emojis), deterministic avatar SVGs, and real session data.
 */

const SessionsPage = {
  filters: {
    range: "7d",
    page: 1,
    limit: 25,
    search: "",
    date: "",
    eventName: "all",
  },
  rawSessions: [],
  searchDebounce: null,
  activeSession: null,
  eventSearch: "",
  eventOrder: "desc",
  // Page and device context sent with every event: not shown as its properties.
  CONTEXT_KEYS: new Set([
    "browser", "os", "device", "screen", "viewport", "language", "country", "country_code", "region", "city",
    "hostname", "site_id", "session_id", "path", "title", "url", "referrer", "search", "hash", "tracking_mode",
    "_ml_tracking_mode", "_ml_revenue",
  ]),

  init() {
    this.datePicker = new PetitstatsCalendarPicker(document.getElementById("sessionDateFilter"));
    this.bindEvents();
    Filters.onChange(() => {
      if (window.App?.currentPage !== "sessions") return;
      this.filters.page = 1;
      this.showListView();
      this.load();
    });
  },

  bindEvents() {
    const searchInput = document.getElementById("sessionSearch");
    if (searchInput) {
      searchInput.addEventListener("input", (e) => {
        clearTimeout(this.searchDebounce);
        this.searchDebounce = setTimeout(() => {
          this.filters.search = e.target.value.trim();
          this.filters.page = 1;
          this.load();
        }, 300);
      });
    }

    const dateFilter = document.getElementById("sessionDateFilter");
    const clearDateBtn = document.getElementById("clearDateFilterBtn");
    if (dateFilter) {
      dateFilter.addEventListener("change", (e) => {
        this.filters.date = e.target.dataset.isoValue || e.target.value;
        if (clearDateBtn) {
          clearDateBtn.style.display = this.filters.date ? "inline-flex" : "none";
        }
        this.filters.page = 1;
        this.load();
      });
    }

    if (clearDateBtn) {
      clearDateBtn.addEventListener("click", () => {
        this.datePicker?.clear();
        this.filters.date = "";
        clearDateBtn.style.display = "none";
        this.filters.page = 1;
        this.load();
      });
    }

    const eventFilter = document.getElementById("sessionEventFilter");
    if (eventFilter) {
      eventFilter.addEventListener("change", (e) => {
        this.filters.eventName = e.target.value;
        this.filters.page = 1;
        this.load();
      });
    }

    const prevBtn = document.getElementById("sessionsPrevPage");
    if (prevBtn) {
      prevBtn.addEventListener("click", () => {
        if (this.filters.page > 1) {
          this.filters.page--;
          this.load();
        }
      });
    }

    const nextBtn = document.getElementById("sessionsNextPage");
    if (nextBtn) {
      nextBtn.addEventListener("click", () => {
        this.filters.page++;
        this.load();
      });
    }

    const backBtn = document.getElementById("btnBackToSessionsList");
    if (backBtn) {
      backBtn.addEventListener("click", () => {
        this.showListView();
      });
    }

    const deleteBtn = document.getElementById("btnDeleteSession");
    if (deleteBtn) {
      deleteBtn.addEventListener("click", () => this.deleteActiveSession());
    }

    const eventSearch = document.getElementById("sessionEventSearch");
    if (eventSearch) {
      eventSearch.addEventListener("input", (e) => {
        this.eventSearch = e.target.value;
        this.renderDetailView(this.activeSession);
      });
    }

    const eventOrder = document.getElementById("sessionEventOrder");
    if (eventOrder) {
      eventOrder.addEventListener("click", () => {
        this.eventOrder = this.eventOrder === "asc" ? "desc" : "asc";
        this.renderDetailView(this.activeSession);
      });
    }
  },

  filterByDay(dayKey) {
    const dateInput = document.getElementById("sessionDateFilter");
    const clearBtn = document.getElementById("clearDateFilterBtn");
    if (dateInput) this.datePicker?.setValue(dayKey);
    if (clearBtn) {
      clearBtn.style.display = "inline-flex";
    }
    this.filters.date = dayKey;
    this.filters.page = 1;
    this.load();
  },

  filterByEvent(eventName) {
    const eventSel = document.getElementById("sessionEventFilter");
    if (eventSel) {
      eventSel.value = eventName;
    }
    this.filters.eventName = eventName;
    this.filters.page = 1;
    this.load();
  },

  async load(range, siteId, customDates) {
    if (range) this.filters.range = range;
    if (customDates !== undefined) this.filters.customDates = customDates;
    const activeSite =
      siteId || window.App?.currentSiteId || this.filters.siteId || "";
    if (activeSite) this.filters.siteId = activeSite;
    Filters.useSite(activeSite);
    Filters.renderBar(document.getElementById("sessionsFilterBar"));

    try {
      if (window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(true, "Loading user sessions...");
      }

      const data = await Api.getSessions(this.filters);
      this.rawSessions = data.sessions || [];
      if (data.available_events) {
        this.renderEventOptions(data.available_events);
      }
      this.renderSessions(this.rawSessions, data.total);
      this.renderPagination(data);
      this.restoreSelectedSession(activeSite);
    } catch (err) {
      window.App?.displayNoDataMessage(siteId);
      console.error("Error loading sessions:", err);
    } finally {
      if (window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(false);
      }
    }
  },

  renderEventOptions(types) {
    const select = document.getElementById("sessionEventFilter");
    if (!select) return;
    const current = this.filters.eventName || "all";
    let optionsHtml = `<option value="all" ${current === "all" ? "selected" : ""}>All events</option>`;

    const hasCurrent = types.some((t) => t.name === current);
    if (current !== "all" && !hasCurrent) {
      optionsHtml += `<option value="${this.escapeHtml(current)}" selected>${this.escapeHtml(current)}</option>`;
    }

    types.forEach((t) => {
      const isSel = t.name === current ? "selected" : "";
      const label = t.count !== undefined ? `${t.name} (${t.count})` : t.name;
      optionsHtml += `<option value="${this.escapeHtml(t.name)}" ${isSel}>${this.escapeHtml(label)}</option>`;
    });

    select.innerHTML = optionsHtml;
  },

  extractDayKey(timestamp) {
    if (!timestamp) return "";
    try {
      const clean = String(timestamp).trim();
      const match = clean.match(/^(\d{4}-\d{2}-\d{2})/);
      return match ? match[1] : clean.substring(0, 10);
    } catch (e) {
      return String(timestamp).substring(0, 10);
    }
  },

  formatDayLabel(dayKey) {
    if (!dayKey) return "";
    try {
      const parts = dayKey.split("-");
      if (parts.length < 3) return dayKey;
      const d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      const now = new Date();
      const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;

      const yesterday = new Date(now);
      yesterday.setDate(yesterday.getDate() - 1);
      const yesterdayKey = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, "0")}-${String(yesterday.getDate()).padStart(2, "0")}`;

      const options = {
        weekday: "long",
        day: "numeric",
        month: "long",
      };
      if (d.getFullYear() !== now.getFullYear()) {
        options.year = "numeric";
      }

      const formatted = d.toLocaleDateString("en-US", options);
      const cap = formatted.charAt(0).toUpperCase() + formatted.slice(1);

      if (dayKey === todayKey) {
        return `Today · ${cap}`;
      } else if (dayKey === yesterdayKey) {
        return `Yesterday · ${cap}`;
      }
      return cap;
    } catch (e) {
      return dayKey;
    }
  },

  renderSessions(sessions, totalCount) {
    const container = document.getElementById("sessionsListContainer");
    const countBadge = document.getElementById("sessionsTotalCount");
    if (!container) return;

    const total = typeof totalCount === "number" ? totalCount : sessions.length;
    if (countBadge) {
      countBadge.textContent = `${total} visitor${total === 1 ? "" : "s"}`;
    }

    if (sessions.length === 0) {
      container.innerHTML = `
                <div class="data-table-card" style="padding: 48px; text-align: center;">
                    <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 4px;">No sessions found</h3>
                    <p style="font-size: 13px; color: var(--text-muted);">No activity was recorded for these filters.</p>
                </div>
            `;
      return;
    }

    // A visitor who comes back moves to the top: sort by latest activity.
    const sorted = [...sessions].sort((a, b) => {
      const ta = a.last_active_at || a.started_at || "";
      const tb = b.last_active_at || b.started_at || "";
      return tb.localeCompare(ta);
    });

    // Group sessions by day to guarantee each day delimiter appears exactly once
    const daysMap = new Map();
    sorted.forEach((s) => {
      const dayKey = this.extractDayKey(s.last_active_at || s.started_at);
      if (!daysMap.has(dayKey)) {
        daysMap.set(dayKey, []);
      }
      daysMap.get(dayKey).push(s);
    });

    let html = "";
    daysMap.forEach((daySessions, dayKey) => {
      if (dayKey) {
        const dayLabel = this.formatDayLabel(dayKey);
        html += `
          <div class="session-day-delimiter">
            <div class="session-day-line"></div>
            <div class="session-day-badge" onclick="SessionsPage.filterByDay('${dayKey}')" title="Filter to ${this.escapeHtml(dayLabel)}">
              ${Icons.get("calendar", { size: 13, color: "var(--text-muted)" })}
              <span>${this.escapeHtml(dayLabel)}</span>
            </div>
            <div class="session-day-line"></div>
          </div>
        `;
      }

      daySessions.forEach((s) => {
        // Seeded by the visitor, so that the visits of one person share their avatar.
        const visitorId = s.visitor_id || s.session_id;
        const avatarUrl = s.avatar_url || Icons.getDiceBearGlyphUrl(visitorId);
        const fallbackSvg = Icons.getIdenticonSvgDataUri(visitorId, 40);
        const visitBadge = this.visitsBadge(s);
        const country = s.country || "Unknown";
        const countryCode = s.country_code || "UN";
        const city = s.city || "";
        const os = s.os || "Unknown OS";
        const browser = s.browser || "Unknown Browser";
        const timeAgo = s.time_ago || s.last_active_at;
        const duration = s.duration_label || "0s";
        const pageviewCount = Number(s.pageview_count) || 0;
        const eventCount = Number(s.event_count) || 0;
        const siteId = window.App?.currentSiteId || "";
        const flowChips = null;

        html += `
          <div class="session-card-item" onclick="SessionsPage.inspectSession('${s.session_id}', '${siteId}')" title="Inspect session journey">
              <div class="session-card-left">
                  <div class="session-avatar-wrap">
                      <img src="${avatarUrl}" alt="Avatar" class="session-avatar-img" onerror="this.onerror=null; this.src='${fallbackSvg}';">
                      <span class="session-status-dot"></span>
                  </div>
                  <div class="session-card-info">
                      <div class="session-card-title-row">
                          <span class="session-visitor-name session-id-full" title="Visitor ${this.escapeHtml(visitorId)}">Visitor ${this.escapeHtml(visitorId)}</span>
                          <span class="session-badge-country">
                              ${Icons.getCountryFlag(countryCode, { size: 14 })}
                              <span>${this.escapeHtml(city ? `${country}, ${city}` : country)}</span>
                          </span>
                          ${visitBadge}
                      </div>
                      <div class="session-card-sub-row">
                          <span class="session-meta-item">${Icons.getBrowserIcon(browser, 14)} ${this.escapeHtml(browser)}</span>
                          <span class="session-meta-divider">·</span>
                          <span class="session-meta-item">${Icons.getOsIcon(os, 14)} ${this.escapeHtml(os)}</span>
                          <span class="session-meta-divider">·</span>
                          <span class="session-meta-item" title="${pageviewCount} page view${pageviewCount === 1 ? "" : "s"}">${Icons.get("file-text", { size: 12 })} ${pageviewCount} page view${pageviewCount === 1 ? "" : "s"}</span>
                          <span class="session-meta-divider">·</span>
                          <span class="session-meta-item" title="${eventCount} event${eventCount === 1 ? "" : "s"}">${Icons.get("zap", { size: 12 })} ${eventCount} event${eventCount === 1 ? "" : "s"}</span>
                          ${s.revenue > 0 ? `<span class="session-meta-divider">·</span><span class="session-revenue-badge" title="Spent during these visits">${Icons.get("banknote", { size: 12 })} ${this.escapeHtml(RevenuePage.money(s.revenue, s.currency))}</span>` : ""}
                          ${flowChips ? `<span class="session-meta-divider">|</span><div class="session-flow-bar">${flowChips}</div>` : ""}
                      </div>
                  </div>
              </div>
              <div class="session-card-right">
                  <div class="session-time-block">
                      <span class="session-card-time">${this.escapeHtml(timeAgo)}</span>
                      <span class="session-card-duration">Duration: ${duration}</span>
                  </div>
                  <span class="session-card-chevron">${Icons.get("chevron-right", { size: 16 })}</span>
              </div>
          </div>
        `;
      });
    });

    container.innerHTML = html;
  },

  renderPagination(data) {
    const info = document.getElementById("sessionsPageInfo");
    const prev = document.getElementById("sessionsPrevPage");
    const next = document.getElementById("sessionsNextPage");

    if (info) {
      info.textContent = `Page ${data.page} of ${data.total_pages} (${data.total} visitor${data.total === 1 ? "" : "s"})`;
    }

    if (prev) prev.disabled = data.page <= 1;
    if (next) next.disabled = data.page >= data.total_pages;
  },

  restoreSelectedSession(siteId) {
    const requestedId = new URLSearchParams(window.location.search).get("session_id");
    if (!requestedId || this.activeSession?.session_id === requestedId || this.restoredSessionId === requestedId) return;
    this.restoredSessionId = requestedId;
    this.inspectSession(requestedId, siteId, false);
  },

  syncSelectedSession(sessionId) {
    const url = new URL(window.location);
    if (sessionId) url.searchParams.set("session_id", sessionId);
    else url.searchParams.delete("session_id");
    window.history.replaceState({}, "", url);
  },

  async inspectSession(sessionId, siteId, syncUrl = true) {
    try {
      const data = await Api.getSessionDetails(sessionId, siteId);
      this.activeSession = data.session;
      this.eventSearch = "";
      this.eventOrder = "desc";
      if (syncUrl) this.syncSelectedSession(data.session.session_id);
      this.renderDetailView(data.session);
      this.showDetailView();
    } catch (err) {
      console.error("Failed to inspect session:", err);
    }
  },

  async deleteActiveSession() {
    const sessionId = this.activeSession?.session_id;
    const siteId = this.filters.siteId || window.App?.currentSiteId || "";
    if (!sessionId) return;
    const visits = Number(this.activeSession?.visit_count) || 1;
    if (!window.confirm(`Delete this visitor and all of their visits (${visits}) and events? This cannot be undone.`)) return;

    const button = document.getElementById("btnDeleteSession");
    if (button) {
      button.disabled = true;
      button.textContent = "Deleting…";
    }
    try {
      await Api.deleteSession(sessionId, siteId);
      this.showListView();
      this.filters.page = 1;
      await this.load();
    } catch (err) {
      window.alert(`Could not delete this session: ${err.message}`);
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = "Delete visitor";
      }
    }
  },

  showDetailView() {
    const list = document.getElementById("sessionsListView");
    const detail = document.getElementById("sessionDetailView");
    if (list) list.style.display = "none";
    if (detail) {
      detail.style.display = "block";
      window.scrollTo({ top: 0, behavior: "smooth" });
    }
  },

  showListView() {
    const list = document.getElementById("sessionsListView");
    const detail = document.getElementById("sessionDetailView");
    if (detail) detail.style.display = "none";
    if (list) list.style.display = "block";
    this.activeSession = null;
    this.restoredSessionId = null;
    this.syncSelectedSession(null);
  },

  renderDetailView(session) {
    if (!session) return;

    const sId = session.session_id || "";
    const countryCode = session.country_code || "UN";

    // Header and metadata
    const setTxt = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    const idEl = document.getElementById("detailSessionId");
    if (idEl) {
      idEl.textContent = session.visitor_id || sId || "Unknown";
      idEl.title = `Visitor ${session.visitor_id || sId}`;
    }

    const visitorId = session.visitor_id || sId;
    const avatarUrl = session.avatar_url || Icons.getDiceBearGlyphUrl(visitorId);
    const fallbackSvg = Icons.getIdenticonSvgDataUri(visitorId, 64);
    const avatarImg = document.getElementById("detailAvatarImg");
    if (avatarImg) {
      avatarImg.src = avatarUrl;
      avatarImg.onerror = () => {
        avatarImg.onerror = null;
        avatarImg.src = fallbackSvg;
      };
    }

    const flagEl = document.getElementById("detailCountryFlag");
    if (flagEl) {
      flagEl.innerHTML = Icons.getCountryFlag(countryCode, { size: 16 });
    }

    const locationLabel = session.city
      ? `${session.country || "Unknown"}, ${session.city}`
      : session.country || "Unknown";
    setTxt("detailCountryName", locationLabel);

    const techEl = document.getElementById("detailTechRow");
    if (techEl) {
      techEl.innerHTML = `
                <span class="tech-item">${Icons.getOsIcon(session.os, 14)} <span>${this.escapeHtml(session.os || "Unknown OS")}</span></span>
                <span class="tech-sep">·</span>
                <span class="tech-item">${Icons.getBrowserIcon(session.browser, 14)} <span>${this.escapeHtml(session.browser || "Unknown Browser")}</span></span>
            `;
    }

    setTxt("detailDuration", session.duration_label || "0s");
    setTxt("detailPageviews", String(session.pageview_count || 0));
    setTxt("detailEventCount", String(session.custom_event_count || 0));
    const startedAt = this.formatEventTime(session.started_at);
    const lastActivity = this.formatEventTime(session.ended_at);
    setTxt("detailStartedAt", startedAt);
    setTxt("detailLastActivity", lastActivity);
    setTxt("detailVisitCount", String(Number(session.visit_count) || 1));

    // Extract first event details for screen / locale
    const firstEvt =
      session.events && session.events[0] ? session.events[0].data : {};
    setTxt("detailLocale", firstEvt.language || "en");

    const devEl = document.getElementById("detailDevice");
    const devVal = session.device || firstEvt.device || "Desktop";
    if (devEl) {
      devEl.innerHTML = `<span class="tech-item">${Icons.getDeviceIcon(devVal, 13)} <span>${this.escapeHtml(devVal)}</span></span>`;
    }
    setTxt("detailScreen", firstEvt.screen || "Not collected");
    setTxt("detailViewport", firstEvt.viewport || "Not collected");
    const consentNotice = document.getElementById("detailConsentNotice");
    if (consentNotice) {
      const hasDetailedEnvironment = Boolean(firstEvt.screen && firstEvt.viewport);
      consentNotice.hidden = hasDetailedEnvironment;
      if (session.tracking_mode === "strict") {
        consentNotice.textContent = firstEvt.screen
          ? "Screen resolution is collected in strict mode, but viewport data requires enriched analytics and visitor consent."
          : "This historical session did not include screen resolution. Reload the tracked site so it receives the latest tracker; existing sessions cannot be updated.";
      } else {
        consentNotice.textContent = "Detailed environment data was not collected for this session. Analytics consent may not have been granted.";
      }
    }

    // Render the journey: every visit of the visitor, each one under its date and time.
    const journeyContainer = document.getElementById("journeyStepsList");
    if (!journeyContainer) return;

    const chronologicalEvents = session.events || [];
    const visits = session.visits?.length
      ? session.visits
      : [{ session_id: sId, visit_number: 1, started_at: session.started_at, duration_seconds: session.duration_seconds, referrer: session.referrer }];
    const query = this.eventSearch.trim().toLocaleLowerCase();
    const matchingCount = chronologicalEvents.filter((evt) => this.eventMatchesSearch(evt, query)).length;
    this.renderJourneyControls(matchingCount, chronologicalEvents.length);

    const newestFirst = this.eventOrder === "desc";
    let html = "";
    (newestFirst ? [...visits].reverse() : visits).forEach((visit) => {
      const visitEvents = chronologicalEvents.filter((evt) => (evt.session_id || sId) === visit.session_id);
      const shown = visitEvents.filter((evt) => this.eventMatchesSearch(evt, query));
      if (shown.length === 0) return;
      const steps = (newestFirst ? [...shown].reverse() : shown)
        .map((evt) => this.journeyStep(evt, visitEvents, visit))
        .join("");
      html += `<section class="visit-block">${this.visitHeader(visit)}<ol class="visit-steps">${steps}</ol></section>`;
    });

    if (html === "") {
      html = `
        <div class="journey-no-results">
          Nothing matches “${this.escapeHtml(this.eventSearch.trim())}”.
        </div>
      `;
    }

    journeyContainer.innerHTML = html;
  },

  /** The date and time a visit started, which separates it from the visitor's other visits. */
  visitHeader(visit) {
    const date = this.parseTimestamp(visit.started_at);
    const when = date
      ? `${date.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", year: "numeric" })} · ${TimeFormat.time(date)}`
      : "";
    const pages = Number(visit.pageview_count) || 0;
    const events = Number(visit.event_count) || 0;
    const details = [
      visit.duration_label,
      visit.referrer || "Direct",
      `${pages} page${pages === 1 ? "" : "s"}`,
      `${events} event${events === 1 ? "" : "s"}`,
    ].filter(Boolean);
    return `
      <header class="visit-head">
        <span class="visit-head-date">${this.escapeHtml(when)}</span>
        <span class="visit-head-details">${details.map((d) => this.escapeHtml(d)).join('<span aria-hidden="true"> · </span>')}</span>
      </header>
    `;
  },

  /** One line per event: time, what happened, its properties and the time spent on it. */
  journeyStep(evt, visitEvents, visit) {
    const isPageview = evt.name === "pageview";
    const data = evt.data && typeof evt.data === "object" ? evt.data : {};
    const amount = Number(data._ml_revenue);
    const isPurchase = !isPageview && amount > 0;
    const title = isPageview ? data.path || "/" : evt.name;
    const context = isPageview ? data.title || "" : data.path || "";
    const date = this.parseTimestamp(evt.timestamp);
    const time = date
      ? TimeFormat.time(date, { seconds: true })
      : evt.timestamp;
    const duration = this.formatStepDuration(visitEvents, visitEvents.indexOf(evt), visit);

    const properties = isPageview
      ? []
      : Object.keys(data)
          .filter((key) => !this.CONTEXT_KEYS.has(key) && data[key] !== null && data[key] !== undefined && data[key] !== "")
          .map((key) => [key, typeof data[key] === "object" ? JSON.stringify(data[key]) : String(data[key])]);
    const propertiesToggle = properties.length
      ? `<button type="button" class="btn-step-props" aria-expanded="false" onclick="SessionsPage.toggleStepProps(this)">
          <span class="props-pill">${Icons.get("tag", { size: 11, color: "#475569" })}<span>${properties.length}</span></span>
          <span class="props-text">Properties</span>
          <span class="props-chevron">${Icons.get("chevron-down", { size: 12, color: "#64748b" })}</span>
        </button>`
      : "";
    const propertiesCard = properties.length
      ? `<div class="step-props-card visit-step-props" hidden>
          <div class="props-table">
            ${properties
              .map(([key, value]) => `
                <div class="props-row">
                  <span class="props-key font-mono">${this.escapeHtml(key)}</span>
                  <span class="props-val font-mono">${this.escapeHtml(value)}</span>
                </div>`)
              .join("")}
          </div>
        </div>`
      : "";

    return `
      <li class="visit-step ${isPageview ? "is-pageview" : "is-event"}${isPurchase ? " is-purchase" : ""}">
        <span class="visit-step-time">${this.escapeHtml(time)}</span>
        <span class="visit-step-icon">${Icons.get(isPageview ? "file-text" : isPurchase ? "banknote" : "zap", { size: 14 })}</span>
        <div class="visit-step-main">
          ${isPageview ? this.pageLink(data, title, "visit-step-title") : `<span class="visit-step-title">${this.escapeHtml(title)}</span>`}
          ${isPurchase ? `<span class="visit-step-amount">${this.escapeHtml(RevenuePage.money(amount, typeof data.currency === "string" ? data.currency.toUpperCase() : null))}</span>` : ""}
          ${context ? (isPageview ? `<span class="visit-step-context">${this.escapeHtml(context)}</span>` : this.pageLink(data, context, "visit-step-context")) : ""}
          ${propertiesToggle}
        </div>
        <span class="visit-step-duration" title="Time until the next event">${duration}</span>
        ${propertiesCard}
      </li>
    `;
  },

  /** "2 visits" on a visitor's card, when they came more than once. */
  visitsBadge(session) {
    const inPeriod = Number(session.visit_count) || 1;
    const visits = Math.max(Number(session.total_visit_count) || 0, inPeriod);
    if (visits < 2) return "";
    const title = visits > inPeriod ? `Returning visitor: ${visits} visits, ${inPeriod} in this period` : `Returning visitor: ${visits} visits in this period`;
    return `<span class="session-badge-returning" title="${title}">${Icons.get("repeat", { size: 11 })}<span>${visits} visits</span></span>`;
  },

  parseTimestamp(ts) {
    if (!ts) return null;
    const d = new Date(ts.includes("T") ? ts : ts.replace(" ", "T") + "Z");
    return isNaN(d.getTime()) ? null : d;
  },

  /** Matches pages (path, title, "page view") and events (name, path, property names and values). */
  eventMatchesSearch(evt, query) {
    if (!query) return true;
    const data = evt?.data || {};
    const isPageview = evt?.name === "pageview";
    const properties = isPageview
      ? []
      : Object.keys(data)
          .filter((key) => !this.CONTEXT_KEYS.has(key))
          .flatMap((key) => [key, typeof data[key] === "object" ? JSON.stringify(data[key]) : data[key]]);
    const searchable = [isPageview ? "page view" : evt?.name, data.path, data.title, data.url, ...properties]
      .filter((value) => value !== null && value !== undefined && value !== "")
      .join(" ")
      .toLocaleLowerCase();
    return searchable.includes(query);
  },

  renderJourneyControls(matchingCount, totalCount) {
    const input = document.getElementById("sessionEventSearch");
    if (input && input.value !== this.eventSearch) input.value = this.eventSearch;

    const button = document.getElementById("sessionEventOrder");
    const label = document.getElementById("sessionEventOrderLabel");
    if (!button || !label) return;
    const newestFirst = this.eventOrder === "desc";
    label.textContent = newestFirst ? "Newest first" : "Oldest first";
    button.setAttribute("aria-pressed", String(newestFirst));
    button.title = newestFirst ? "Show oldest events first" : "Show newest events first";
    button.setAttribute(
      "aria-label",
      `${label.textContent}; ${matchingCount} of ${totalCount} events shown. Toggle event order`,
    );
  },

  formatEventTime(ts) {
    if (!ts) return "";
    try {
      const d = new Date(ts.includes("T") ? ts : ts.replace(" ", "T") + "Z");
      if (isNaN(d.getTime())) return ts;
      const dayMonth = d.toLocaleDateString("en-US", {
        day: "numeric",
        month: "short",
      });
      const timeStr = TimeFormat.time(d, { seconds: true });
      return `${dayMonth}, ${timeStr}`;
    } catch (e) {
      return ts;
    }
  },

  formatStepDuration(events, idx, session) {
    if (!events || events.length === 0) return "0s";
    const current = events[idx];
    const parseTs = (ts) =>
      new Date(ts.includes("T") ? ts : ts.replace(" ", "T") + "Z").getTime();

    let diffSec = 0;
    if (idx + 1 < events.length) {
      const t1 = parseTs(current.timestamp);
      const t2 = parseTs(events[idx + 1].timestamp);
      diffSec = Math.max(0, Math.round((t2 - t1) / 1000));
    } else {
      const totalDur =
        session && session.duration_seconds ? session.duration_seconds : 0;
      const curOffset = current.offset_seconds || 0;
      diffSec = Math.max(0, totalDur - curOffset);
    }

    if (diffSec === 0) {
      return "0s";
    }
    const days = Math.floor(diffSec / 86400);
    diffSec %= 86400;
    const hours = Math.floor(diffSec / 3600);
    diffSec %= 3600;
    const mins = Math.floor(diffSec / 60);
    const secs = diffSec % 60;
    const parts = [];
    if (days > 0) parts.push(`${days}d`);
    if (hours > 0 || days > 0) parts.push(`${hours}h`);
    if (mins > 0 || hours > 0 || days > 0) parts.push(`${mins}m`);
    if (secs > 0 || parts.length === 0) parts.push(`${secs}s`);
    return parts.join(" ");
  },

  /** The page as a link that opens it on the website, when its address is known. */
  pageLink(data, label, className) {
    const host = String(data.hostname || "");
    const path = String(data.path || "");
    if (!/^[a-z0-9.-]+$/i.test(host) || !path.startsWith("/")) {
      return `<span class="${className}">${this.escapeHtml(label)}</span>`;
    }
    // The tracker only sends the hostname: a page on the dashboard's own host keeps its port.
    const local = /^(localhost|127\.)/.test(host) || host.endsWith(".localhost");
    const origin = host === window.location.hostname ? window.location.origin : `${local ? "http" : "https"}://${host}`;
    const url = `${origin}${path}`;
    return `<a class="${className} visit-step-link" href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="Open ${this.escapeHtml(url)}">${this.escapeHtml(label)}</a>`;
  },

  toggleStepProps(button) {
    const card = button.closest(".visit-step")?.querySelector(".visit-step-props");
    if (!card) return;
    card.hidden = !card.hidden;
    button.classList.toggle("active", !card.hidden);
    button.setAttribute("aria-expanded", String(!card.hidden));
  },

  escapeHtml(str) {
    if (!str) return "";
    return String(str).replace(
      /[&<>"']/g,
      (m) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[m],
    );
  },
};

window.SessionsPage = SessionsPage;
