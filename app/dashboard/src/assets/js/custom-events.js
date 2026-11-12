/**
 * Petitstats – Events Page Controller
 */

const EventsPage = {
  filters: {
    range: "7d",
    page: 1,
    limit: 50,
    eventName: "all",
    sessionId: "",
  },
  eventsMap: {},
  // Event names the page focuses on; empty means every event.
  selected: new Set(),
  types: [],
  breakdownQuery: "",
  breakdownLimit: 10,
  requestId: 0,
  // How many series the chart plots when no event is selected.
  TOP_SERIES: 8,
  PALETTE: ["#f59e0b", "#2563eb", "#8b5cf6", "#10b981", "#ef4444", "#06b6d4", "#ec4899", "#84cc16", "#f97316", "#6366f1"],

  // ── Init ────────────────────────────────────────────────────────────────

  init() {
    this.trendChart = new PetitstatsChart("eventsTrendChart", "eventsTrendTooltip", {
      activeSeries: ["events"],
      sortTooltipMetrics: true,
      hideZeroTooltipMetrics: true,
      lockTooltipOnClick: true,
      highlightAnomalies: true,
      onPointClick: (item) => this.openSessionsForPoint(item),
      onTooltipMetricClick: (item, seriesKey) => this.openSessionsForPoint(item, seriesKey),
      seriesConfig: {
        events: {
          key: "events", label: "Events", singular: "event", color: "#f59e0b",
          gradientStart: "rgba(245, 158, 11, 0.24)", gradientEnd: "rgba(245, 158, 11, 0.03)", lineWidth: 2.75,
        },
      },
    });
    this.bindEvents();
  },

  bindEvents() {
    document.getElementById("eventsPrevPage")?.addEventListener("click", () => {
      if (this.filters.page > 1) {
        this.filters.page--;
        this.load();
      }
    });
    document.getElementById("eventsNextPage")?.addEventListener("click", () => {
      this.filters.page++;
      this.load();
    });

    document
      .getElementById("clearSessionFilterBtn")
      ?.addEventListener("click", () => {
        this.filters.sessionId = "";
        document.getElementById("activeSessionFilterBox").style.display =
          "none";
        this.filters.page = 1;
        this.load();
      });

    document.getElementById("eventsSelectionReset")?.addEventListener("click", () => this.setSelection(new Set()));
    document.getElementById("eventsBreakdownSearch")?.addEventListener("input", (event) => {
      this.breakdownQuery = event.target.value;
      this.breakdownLimit = 10;
      this.renderBreakdown();
    });
    document.getElementById("eventsBreakdownMore")?.addEventListener("click", () => {
      this.breakdownLimit += 25;
      this.renderBreakdown();
    });
    const body = document.getElementById("eventsBreakdownBody");
    body?.addEventListener("click", (event) => {
      const row = event.target.closest?.("tr[data-event]");
      if (row) this.toggleEvent(row.dataset.event);
    });
    body?.addEventListener("keydown", (event) => {
      const row = event.target.closest?.("tr[data-event]");
      if (row && (event.key === "Enter" || event.key === " ")) {
        event.preventDefault();
        this.toggleEvent(row.dataset.event);
      }
    });
  },

  // ── Public API ───────────────────────────────────────────────────────────

  filterBySession(sessionId) {
    this.filters.sessionId = sessionId;
    this.filters.page = 1;
    const box = document.getElementById("activeSessionFilterBox");
    if (box) {
      box.style.display = "inline-flex";
      document.getElementById("activeSessionIdLabel").textContent =
        sessionId.substring(0, 10) + "…";
    }
    this.load();
  },

  /** Open the sessions recorded on the day (or hour) represented by a chart point. */
  openSessionsForPoint(item, seriesKey = "") {
    const timestamp = Number(item?.timestamp);
    if (!Number.isFinite(timestamp)) return;
    const day = new Date(timestamp * 1000).toISOString().slice(0, 10);
    const sessions = window.SessionsPage;
    if (sessions?.filters) {
      sessions.filters.date = day;
      sessions.filters.eventName = seriesKey
        ? this.trendChart?.seriesConfig?.[seriesKey]?.label || "all"
        : "all";
      sessions.filters.page = 1;
    }
    const dateInput = document.getElementById("sessionDateFilter");
    if (dateInput) window.SessionsPage?.datePicker?.setValue(day);
    const eventSelect = document.getElementById("sessionEventFilter");
    if (eventSelect && sessions?.filters?.eventName) eventSelect.value = sessions.filters.eventName;
    const clearButton = document.getElementById("clearDateFilterBtn");
    if (clearButton) clearButton.style.display = "inline-flex";
    window.Filters?.openSessions();
  },

  // ── Selection ────────────────────────────────────────────────────────────

  /** From "all events", a click focuses on one event; afterwards it adds or removes events. */
  toggleEvent(name) {
    const next = new Set(this.selected);
    if (next.has(name)) next.delete(name);
    else next.add(name);
    this.setSelection(next);
  },

  setSelection(selected) {
    this.selected = selected;
    this.filters.page = 1;
    this.load();
  },

  selectionTitle() {
    const names = [...this.selected];
    if (names.length === 0) return "All events";
    if (names.length === 1) return names[0];
    return `${names[0]} + ${names.length - 1} more`;
  },

  /** Selected events get distinct colors in selection order; otherwise colors follow the ranking. */
  colorFor(name) {
    const selectedIndex = [...this.selected].indexOf(name);
    const index = selectedIndex >= 0 ? selectedIndex : this.types.findIndex((type) => type.name === name);
    return this.PALETTE[(index < 0 ? 0 : index) % this.PALETTE.length];
  },

  // ── Data loading ─────────────────────────────────────────────────────────

  async load(range, siteId, customDates) {
    if (range) this.filters.range = range;
    if (customDates !== undefined) this.filters.customDates = customDates;
    const activeSite = siteId || window.App?.currentSiteId || this.filters.siteId || "";
    if (activeSite) this.filters.siteId = activeSite;
    // Rapid clicks start several requests: only the latest one may render.
    const requestId = ++this.requestId;

    try {
      if (window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(true, "Loading events stream...");
      }

      const data = await Api.getEvents({ ...this.filters, events: [...this.selected] });
      if (requestId !== this.requestId) return;
      this.types = data.types || [];
      this.renderSelectionHead();
      this.renderInsights(data.summary || { events: data.total || 0, visitors: 0, sessions: 0, previous: null }, this.types);
      this.renderTrend(data.chart_data || [], data.series || []);
      this.renderBreakdown();
      this.renderEvents(data.events || []);
      this.renderPagination(data);
    } catch (err) {
      window.App?.displayNoDataMessage(siteId);
      console.error("Error loading events:", err);
    } finally {
      if (requestId === this.requestId && window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(false);
      }
    }
  },

  // ── Rendering ────────────────────────────────────────────────────────────

  renderSelectionHead() {
    const title = document.getElementById("eventsSelectionTitle");
    const reset = document.getElementById("eventsSelectionReset");
    const stream = document.getElementById("eventsStreamTitle");
    if (title) title.textContent = this.selectionTitle();
    if (reset) reset.hidden = this.selected.size === 0;
    if (stream) stream.textContent = this.selected.size ? `Event stream · ${this.selectionTitle()}` : "Real-Time Event Stream";
  },

  /** Change against the previous period, or "" when there is nothing to compare with. */
  deltaBadge(current, previous) {
    if (previous === null || previous === undefined) return "";
    if (previous === 0) {
      return current > 0 ? '<span class="event-delta event-delta-up">New</span>' : '<span class="event-delta">—</span>';
    }
    const change = ((current - previous) / previous) * 100;
    const rounded = Math.abs(change) < 10 ? change.toFixed(1) : Math.round(change).toString();
    if (Number(rounded) === 0) return '<span class="event-delta">0%</span>';
    const up = change > 0;
    return `<span class="event-delta ${up ? "event-delta-up" : "event-delta-down"}" title="vs previous period">${up ? "+" : ""}${rounded}%</span>`;
  },

  renderInsights(summary, types) {
    const container = document.getElementById("eventInsightsStrip");
    if (!container) return;
    const previous = summary.previous || null;
    const perVisitor = summary.visitors ? summary.events / summary.visitors : 0;
    const previousPerVisitor = previous && previous.visitors ? previous.events / previous.visitors : (previous ? 0 : null);

    const metrics = [
      { label: "Events", value: summary.events, delta: this.deltaBadge(summary.events, previous?.events ?? null) },
      { label: "Unique visitors", value: summary.visitors, delta: this.deltaBadge(summary.visitors, previous?.visitors ?? null) },
      { label: "Sessions", value: summary.sessions, delta: this.deltaBadge(summary.sessions, previous?.sessions ?? null) },
      this.selected.size
        ? { label: "Events per visitor", value: perVisitor.toFixed(1), delta: this.deltaBadge(perVisitor, previousPerVisitor) }
        : { label: "Event types", value: types.length, delta: "" },
    ];
    container.innerHTML = metrics.map((metric) => `
      <div class="event-insight">
        <span class="event-insight-label">${this.esc(metric.label)}</span>
        <div class="event-insight-metric">
          <strong class="event-insight-value">${this.esc(typeof metric.value === "number" ? metric.value.toLocaleString() : metric.value)}</strong>
          ${metric.delta}
        </div>
      </div>`).join("");
  },

  renderTrend(points, series) {
    // Selected events, or the most frequent ones: 100+ overlapping lines are unreadable.
    const plotted = this.selected.size
      ? series.filter((item) => this.selected.has(item.name))
      : series.slice(0, this.TOP_SERIES);
    this.trendChart.seriesConfig = Object.fromEntries(series.map((item) => [item.key, {
      key: item.key, label: item.name, singular: "event", color: this.colorFor(item.name),
      gradientStart: "rgba(255, 255, 255, 0)", gradientEnd: "rgba(255, 255, 255, 0)", lineWidth: 2.4,
    }]));
    this.trendChart?.setData(points, plotted.map((item) => item.key));

    const legend = document.getElementById("eventsTrendLegend");
    if (!legend) return;
    const chips = plotted.map((item) => `
      <span class="chart-metric-indicator">
        <span class="metric-color-dot" style="background:${this.colorFor(item.name)}"></span>
        <span class="metric-name">${this.esc(item.name)}</span>
      </span>`).join("");
    const note = !this.selected.size && series.length > plotted.length
      ? `<span class="events-trend-note">Top ${plotted.length} of ${series.length} events · select events below to compare others</span>`
      : "";
    legend.innerHTML = chips + note || '<span class="events-trend-note">No events in this period</span>';
  },

  renderBreakdown() {
    const body = document.getElementById("eventsBreakdownBody");
    const footer = document.getElementById("eventsBreakdownFooter");
    const more = document.getElementById("eventsBreakdownMore");
    if (!body) return;
    const query = this.breakdownQuery.trim().toLowerCase();
    const total = this.types.reduce((sum, type) => sum + type.count, 0);
    // Selected events are pinned on top and stay visible whatever the search
    // or the row limit, so they can always be found and deselected.
    const pinned = this.types.filter((type) => this.selected.has(type.name));
    const matches = this.types.filter((type) => !this.selected.has(type.name) && type.name.toLowerCase().includes(query));
    const visible = [...pinned, ...matches.slice(0, this.breakdownLimit)];

    body.innerHTML = visible.length
      ? visible.map((type) => {
        const selected = this.selected.has(type.name);
        const dimmed = this.selected.size > 0 && !selected;
        const share = total ? (type.count / total) * 100 : 0;
        const lastPinned = selected && type === pinned[pinned.length - 1] && matches.length > 0;
        return `<tr data-event="${this.esc(type.name)}" class="events-breakdown-row${selected ? " is-selected" : ""}${dimmed ? " is-dimmed" : ""}${lastPinned ? " is-last-pinned" : ""}" tabindex="0" role="button" aria-pressed="${selected}">
          <td><span class="events-breakdown-name"><span class="chart-series-dot" style="background:${this.colorFor(type.name)}"></span><span>${this.esc(type.name)}</span></span></td>
          <td class="num">${type.count.toLocaleString()}</td>
          <td class="num">${(type.visitors || 0).toLocaleString()}</td>
          <td class="share"><span class="events-share"><span class="events-share-bar"><span style="width:${share.toFixed(1)}%;background:${this.colorFor(type.name)}"></span></span><span class="events-share-value">${share.toFixed(1)}%</span></span></td>
          <td class="num">${this.deltaBadge(type.count, type.previous_count ?? null) || '<span class="event-delta">—</span>'}</td>
        </tr>`;
      }).join("")
      : `<tr><td colspan="5" class="empty-state">${this.types.length ? "No matching events" : "No events in this period"}</td></tr>`;

    const shown = Math.min(matches.length, this.breakdownLimit);
    if (footer) {
      footer.textContent = pinned.length
        ? `${pinned.length} selected · showing ${shown} of ${matches.length} other events`
        : (matches.length ? `Showing ${shown} of ${matches.length} events` : "");
    }
    if (more) more.hidden = shown >= matches.length;
  },

  renderEvents(events) {
    const container = document.getElementById("eventsListContainer");
    if (!container) return;

    if (events.length === 0) {
      container.innerHTML = `
                <div class="empty-state" style="padding: 48px; text-align: center;">
                    <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 4px;">No events recorded</h3>
                    <p style="font-size: 13px; color: var(--text-muted);">No activity matches your current filters.</p>
                </div>`;
      return;
    }

    this.eventsMap = Object.fromEntries(events.map((e) => [e.id, e]));

    const EXCLUDE_KEYS = new Set([
      "browser",
      "os",
      "device",
      "screen",
      "viewport",
      "language",
      "country",
      "country_code",
      "region",
      "city",
      "hostname",
      "site_id",
      "session_id",
      "tracking_mode",
      "_ml_tracking_mode",
      "path",
      "title",
      "url",
      "referrer",
      "search",
      "hash",
    ]);

    const rows = events
      .map((e, idx) => {
        const d = e.data || {};
        const isPv = e.name === "pageview";
        const iconSvg = isPv
          ? Icons.get("file-text", { size: 15, color: "#f59e0b" })
          : Icons.get("zap", { size: 15, color: "#f59e0b" });
        const title = isPv ? d.path || "/" : e.name;
        const subtitle = isPv ? d.title || "" : d.path || "";
        const shortSession = (e.session_id || "").substring(0, 8);
        const country = d.country || "";

        // Custom payload for non-pv events
        const customPayload = {};
        if (!isPv && d && typeof d === "object") {
          Object.entries(d).forEach(([k, v]) => {
            if (
              EXCLUDE_KEYS.has(k) ||
              v === null ||
              v === undefined ||
              v === ""
            )
              return;
            customPayload[k] = v;
          });
        }
        const customKeys = Object.keys(customPayload);
        const hasCustom = customKeys.length > 0;
        const propId = `evtProps_${e.id}_${idx}`;

        const metaItems = [
          `<span class="journey-step-dur" style="cursor:pointer;" onclick="EventsPage.filterBySession('${e.session_id}')" title="Filter by session">
                    ${Icons.get("user", { size: 12, color: "#64748b" })}
                    <span>${this.esc(shortSession)}</span>
                 </span>`,
          country
            ? `<span class="journey-step-dur">${Icons.getCountryFlag(d.country_code || "UN", { size: 12 })} <span>${this.esc(country)}</span></span>`
            : "",
          d.browser
            ? `<span class="journey-step-dur">${Icons.getBrowserIcon(d.browser, 13)} <span>${this.esc(d.browser)}</span></span>`
            : "",
          d.os
            ? `<span class="journey-step-dur">${Icons.getOsIcon(d.os, 13)} <span>${this.esc(d.os)}</span></span>`
            : "",
        ]
          .filter(Boolean)
          .join("");

        return `
                <div class="journey-step-item">
                    <div class="journey-step-left">
                        <div class="journey-step-circle">${idx + 1}</div>
                        <div class="journey-step-line"></div>
                    </div>
                    <div class="journey-step-content">
                        <div class="journey-step-top">
                            <div class="journey-step-title-wrap">
                                <span class="journey-step-icon">${iconSvg}</span>
                                <span class="journey-step-path">${this.esc(title)}</span>
                                ${subtitle ? `<span class="journey-step-subtitle font-mono">${this.esc(subtitle)}</span>` : ""}
                            </div>
                            <span class="journey-step-time" title="${e.timestamp}">${e.time_ago || e.timestamp}</span>
                        </div>
                        <div class="journey-step-meta-row">
                            ${metaItems}
                            ${
                              hasCustom
                                ? `
                                <button type="button" class="btn-step-props active" onclick="EventsPage.toggleStepProps('${propId}', this)">
                                    <span class="props-text">Properties (${customKeys.length})</span>
                                    <span class="props-chevron">${Icons.get("chevron-down", { size: 12, color: "#64748b" })}</span>
                                </button>`
                                : ""
                            }
                            <button type="button" class="btn-inspect-json" onclick="EventsPage.inspectEvent(${e.id})">JSON</button>
                        </div>
                        ${
                          hasCustom
                            ? `
                            <div class="step-props-card" id="${propId}">
                                <div class="props-table">
                                    ${customKeys
                                      .map((k) => {
                                        const v = customPayload[k];
                                        const formatted =
                                          typeof v === "object"
                                            ? JSON.stringify(v)
                                            : String(v);
                                        return `<div class="props-row">
                                            <span class="props-key font-mono">${this.esc(k)}</span>
                                            <span class="props-val font-mono">${this.esc(formatted)}</span>
                                        </div>`;
                                      })
                                      .join("")}
                                </div>
                            </div>`
                            : ""
                        }
                    </div>
                </div>`;
      })
      .join("");

    const endMarker = `
            <div class="journey-step-item journey-step-end">
                <div class="journey-step-left">
                    <div class="journey-step-circle journey-circle-dot">
                        <span class="journey-inner-dot"></span>
                    </div>
                </div>
                <div class="journey-step-content" style="padding-bottom:0; display:flex; align-items:center; min-height:28px;">
                    <span class="journey-end-text">${events.length} event${events.length === 1 ? "" : "s"}, page ${this.filters.page}</span>
                </div>
            </div>`;

    container.innerHTML = `<div class="journey-steps-container">${rows}${endMarker}</div>`;
  },

  toggleStepProps(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    const isHidden = window.getComputedStyle(el).display === "none";
    el.style.display = isHidden ? "block" : "none";
    if (btn) btn.classList.toggle("active", isHidden);
  },

  renderPagination(data) {
    const info = document.getElementById("eventsPageInfo");
    const footer = document.getElementById("eventsFooterCount");
    const prev = document.getElementById("eventsPrevPage");
    const next = document.getElementById("eventsNextPage");
    if (info) info.textContent = `Page ${data.page} of ${data.total_pages}`;
    if (footer)
      footer.textContent = `Showing ${(data.events || []).length} of ${data.total} recorded events`;
    if (prev) prev.disabled = data.page <= 1;
    if (next) next.disabled = data.page >= data.total_pages;
  },

  inspectEvent(id) {
    const event = this.eventsMap[id];
    if (!event) return;
    const modal = document.getElementById("payloadModal");
    const jsonBox = document.getElementById("modalJsonContent");
    if (!modal || !jsonBox) return;
    const rawJson = JSON.stringify(event.data, null, 2);
    jsonBox.dataset.rawText = rawJson;
    if (window.ClipboardHelper && typeof window.ClipboardHelper.highlightJson === "function") {
      jsonBox.innerHTML = window.ClipboardHelper.highlightJson(rawJson);
    } else {
      jsonBox.textContent = rawJson;
    }
    modal.classList.add("active");
  },

  esc(str) {
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

window.EventsPage = EventsPage;
