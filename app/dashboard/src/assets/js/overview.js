/**
 * Petitstats Overview Page Controller
 * Umami-inspired layout featuring:
 * - Five core metric cards with trend pills
 * - Full-width chart, switchable between line and vertical bars
 * - 4 spacious analytical breakdown cards with Lucide icons (no emojis)
 */

const OverviewPage = {
  chart: null,
  currentData: null,
  chartType: "line",
  activePageTab: "paths",
  activeEnvTab: "browsers",

  init() {
    this.chart = new PetitstatsChart("overviewChart", "chartTooltip", {
      activeSeries: ["pageviews", "visitors"],
      onPointClick: (item) => this.openSessionsForPoint(item),
    });
    this.botsChart = new PetitstatsChart("botsChart", "botsChartTooltip", {
      sortTooltipMetrics: true,
      hideZeroTooltipMetrics: true,
      seriesConfig: {},
      gridLines: 4,
      gridDash: [4, 4],
      markLastPoint: false,
      minScale: 4,
    });
    document.getElementById("botSideTabs")?.addEventListener("click", (e) => {
      const btn = e.target.closest(".chart-toggle-btn");
      if (!btn) return;
      this.activeBotSide = btn.dataset.side;
      this.renderBotSide();
    });
    document.getElementById("botTabs")?.addEventListener("click", (e) => {
      const btn = e.target.closest(".chart-toggle-btn");
      if (!btn) return;
      this.activeBotTab = btn.dataset.tab;
      this.renderBotTabs();
      this.renderBotCategory();
    });
    this.bindEvents();
    this.bindFilterEvents();
  },

  /**
   * Hover actions on breakdown rows:
   *  - funnel icon  -> toggle the value in the shared filters (multi-select)
   *  - people icon  -> add the value to the filters and open the related sessions
   */
  bindFilterEvents() {
    const grid = document.querySelector("#page-overview .overview-breakdowns-grid");
    grid?.addEventListener("click", (e) => {
      const btn = e.target.closest(".pill-action-btn");
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      const { filterDim: dim, filterValue: value } = btn.dataset;
      if (!dim) return;
      if (btn.classList.contains("pill-sessions-btn")) {
        Filters.add(dim, value, { silent: true });
        Filters.persist();
        Filters.openSessions();
      } else {
        Filters.toggle(dim, value);
      }
    });

    Filters.onChange(() => {
      if (window.App?.currentPage === "overview") {
        this.load(window.App.currentRange, window.App.currentSiteId, window.App.customDates);
      }
    });
  },

  /** Hover action buttons appended to each breakdown row. */
  rowActions(dim, value, label) {
    const active = Filters.has(dim, value);
    const v = Filters.escape(value);
    const l = Filters.escape(label || value);
    return `
      <div class="pill-actions">
        <button type="button" class="pill-action-btn pill-filter-btn${active ? " active" : ""}" data-filter-dim="${dim}" data-filter-value="${v}"
                title="${active ? `Remove filter “${l}”` : `Filter by “${l}”`}" aria-pressed="${active}">
          ${Icons.get("filter", { size: 13 })}
        </button>
        <button type="button" class="pill-action-btn pill-sessions-btn" data-filter-dim="${dim}" data-filter-value="${v}"
                title="View sessions for “${l}”">
          ${Icons.get("users", { size: 13 })}
        </button>
      </div>`;
  },

  rowClass(dim, value) {
    return Filters.has(dim, value) ? "clean-pill-row is-filterable is-filtered" : "clean-pill-row is-filterable";
  },

  bindEvents() {
    // Multi-series superposition toggles (Views / Visitors / Visits)
    const seriesBtns = document.querySelectorAll(
      "#chartSeriesToggles .chart-toggle-btn",
    );
    seriesBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        const seriesKey = btn.dataset.series;
        if (this.chart) {
          this.chart.toggleSeries(seriesKey);
          if (this.chart.isSeriesActive(seriesKey)) {
            btn.classList.add("active");
          } else {
            btn.classList.remove("active");
          }
        }
      });
    });

    const chartTypeBtns = document.querySelectorAll(
      "#chartTypeToggles .chart-toggle-btn",
    );
    chartTypeBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        this.chartType = btn.dataset.chartType;
        chartTypeBtns.forEach((b) => b.classList.toggle("active", b === btn));
        this.chart?.setChartType(this.chartType);
      });
    });

    // Top pages tabs (Paths / Titles)
    const pageTabBtns = document.querySelectorAll(
      "#pageTabs .chart-toggle-btn",
    );
    pageTabBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        pageTabBtns.forEach((b) => b.classList.remove("active"));
        btn.classList.add("active");
        this.activePageTab = btn.dataset.tab;
        this.renderPages();
      });
    });

    // Environment tabs (Browsers / OS / Devices)
    const envTabBtns = document.querySelectorAll("#envTabs .chart-toggle-btn");
    envTabBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        envTabBtns.forEach((b) => b.classList.remove("active"));
        btn.classList.add("active");
        this.activeEnvTab = btn.dataset.tab;

        const header = document.getElementById("envColumnHeader");
        if (header) {
          header.textContent =
            this.activeEnvTab === "browsers"
              ? "Browser"
              : this.activeEnvTab === "os"
                ? "Operating System"
                : "Device";
        }

        this.renderEnvironment();
      });
    });
  },

  async load(range = "7d", siteId = "", customDates = null) {
    const noDataEl = document.getElementById("no-data-yet");
    const dataContainer = document.getElementById("overviewDataContainer");
    if (noDataEl) noDataEl.style.display = "none";
    if (dataContainer) dataContainer.style.display = "block";

    const activeSite = siteId || window.App?.currentSiteId || "";
    Filters.useSite(activeSite);
    const token = (this._loadToken = (this._loadToken || 0) + 1);
    try {
      if (window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(true, "Loading analytics overview...");
      }

      const data = await Api.getStats(range, activeSite, customDates);
      // A newer request (e.g. another filter click) superseded this one
      if (token !== this._loadToken) return;
      this.currentData = data;
      this.updateTrackingModeNotice(activeSite);

      // Populate sites dropdown if needed
      if (window.App && typeof window.App.updateSiteSelect === "function") {
        window.App.updateSiteSelect(data.available_sites || []);
      }

      Filters.renderBar(document.getElementById("overviewFilterBar"), {
        sessionsCount: data.summary?.session_count,
        showSessionsLink: true,
      });
      this.renderSummary(data.summary);
      this.renderChart(data.timeseries);
      this.renderPages();
      this.renderReferrers();
      this.renderEnvironment();
      this.renderCountries();
      this.renderBots(data.bots);
    } catch (err) {
      if (token !== this._loadToken) return;
      window.App?.displayNoDataMessage(siteId);
      console.error("Error loading overview:", err);
    } finally {
      if (token === this._loadToken && window.App && typeof window.App.setLoading === "function") {
        window.App.setLoading(false);
      }
    }
  },

  async updateTrackingModeNotice(siteId) {
    const notice = document.getElementById("trackingModeNotice");
    if (!notice) return;
    if (!siteId) { notice.hidden = true; return; }
    try {
      const { snippet } = await Api.getTrackingConfig(siteId);
      const strictMode = /data-privacy-mode=["']strict["']/.test(snippet || "");
      notice.hidden = !strictMode;
    } catch (_) {
      // The notice is advisory; analytics rendering must not fail if the
      // protected tracking configuration cannot be read.
      notice.hidden = true;
    }
  },

  formatNumber(num) {
    if (!num) return "0";
    if (num >= 1000000) return (num / 1000000).toFixed(1) + "M";
    if (num >= 1000) return (num / 1000).toFixed(1) + "k";
    return num.toLocaleString();
  },

  formatDuration(seconds) {
    if (!seconds || seconds <= 0) return "0s";
    const mins = Math.floor(seconds / 60);
    const secs = Math.round(seconds % 60);
    if (mins === 0) return `${secs}s`;
    return `${mins}m ${secs}s`;
  },

  applyDeltaBadge(badgeId, textId, deltaStr, inverse = false) {
    const badge = document.getElementById(badgeId);
    const text = document.getElementById(textId);
    if (!badge) return;

    badge.classList.remove("positive", "negative", "neutral");
    const arrow = badge.querySelector(".delta-arrow");

    if (
      !deltaStr ||
      deltaStr === "0" ||
      deltaStr === "0%" ||
      deltaStr === "0s"
    ) {
      badge.classList.add("neutral");
      if (arrow) arrow.textContent = "–";
      if (text) text.textContent = "0%";
    } else if (deltaStr.startsWith("+")) {
      badge.classList.add(inverse ? "negative" : "positive");
      if (arrow) arrow.textContent = "↑";
      if (text) text.textContent = deltaStr.replace("+", "");
    } else if (deltaStr.startsWith("-")) {
      badge.classList.add(inverse ? "positive" : "negative");
      if (arrow) arrow.textContent = "↓";
      if (text) text.textContent = deltaStr.replace("-", "");
    } else {
      badge.classList.add("neutral");
      if (arrow) arrow.textContent = "–";
      if (text) text.textContent = deltaStr;
    }
  },

  updateMainChartNumbers(sum) {
    if (!sum) return;
    const setTxt = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = this.formatNumber(val);
    };
    setTxt("chartTotalViews", sum.pageviews || 0);
    setTxt("chartTotalVisitors", sum.visitors || 0);
  },

  renderSummary(sum) {
    if (!sum) return;

    this.updateMainChartNumbers(sum);

    const setTxt = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    // Core overview metrics
    setTxt("statVisitors", this.formatNumber(sum.visitors || 0));
    setTxt("statReturningVisitors", this.formatNumber(sum.returning_visitors || 0));
    setTxt("statSessions", this.formatNumber(sum.sessions || 0));
    setTxt("statPageviews", this.formatNumber(sum.pageviews || 0));
    setTxt("statBounceRate", `${sum.bounce_rate || 0}%`);
    setTxt("statDuration", this.formatDuration(sum.avg_duration_seconds));

    // Real deltas
    if (sum.deltas) {
      this.applyDeltaBadge(
        "deltaVisitorsBadge",
        "deltaVisitorsText",
        sum.deltas.visitors || "0",
      );
      this.applyDeltaBadge(
        "deltaReturningBadge",
        "deltaReturningText",
        sum.deltas.returning_visitors || "0",
      );
      this.applyDeltaBadge(
        "deltaSessionsBadge",
        "deltaSessionsText",
        sum.deltas.sessions || "0",
      );
      this.applyDeltaBadge(
        "deltaPageviewsBadge",
        "deltaPageviewsText",
        sum.deltas.pageviews || "0",
      );
      this.applyDeltaBadge(
        "deltaBounceBadge",
        "deltaBounceText",
        sum.deltas.bounce_rate || "0%",
        true,
      );
      this.applyDeltaBadge(
        "deltaDurationBadge",
        "deltaDurationText",
        sum.deltas.duration || "0s",
      );
    }

    // Live visitors indicator in header
    const liveElem = document.getElementById("liveVisitorsCount");
    if (liveElem) {
      const count = sum.live_visitors || 0;
      liveElem.textContent = `${count} live visitor${count === 1 ? "" : "s"}`;
    }
  },

  renderChart(timeseries) {
    if (this.chart) {
      this.chart.setData(this.localizeHourlyChartLabels(timeseries));
    }
  },

  /**
   * Timestamps are sent as Unix seconds. Hourly overview buckets used to show
   * their UTC label directly, which made the chart appear offset from the
   * viewer's clock. Keep the server-side bucket intact and format its label in
   * the browser's timezone.
   */
  localizeHourlyChartLabels(timeseries) {
    if (!Array.isArray(timeseries) || timeseries.length === 0) return timeseries;
    const isHourly = timeseries.every((point) => Number(point.interval_hours) === 1);
    if (!isHourly) return timeseries;

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
      weekday: "long", year: "numeric", month: "long", day: "numeric",
    });
    const fullFormatter = new Intl.DateTimeFormat(undefined, {
      weekday: "long", year: "numeric", month: "long", day: "numeric", ...TimeFormat.options(),
    });

    return timeseries.map((point) => {
      const timestamp = Number(point.timestamp);
      if (!Number.isFinite(timestamp)) return point;
      const date = new Date(timestamp * 1000);
      return {
        ...point,
        label: TimeFormat.hour(date),
        date_label: dateFormatter.format(date),
        full_label: fullFormatter.format(date),
      };
    });
  },

  /** Open the Sessions page with the day represented by a chart point selected. */
  openSessionsForPoint(item) {
    const timestamp = Number(item?.timestamp);
    if (!Number.isFinite(timestamp)) return;

    const day = new Date(timestamp * 1000).toISOString().slice(0, 10);
    const sessions = window.SessionsPage;
    if (sessions?.filters) {
      sessions.filters.date = day;
      sessions.filters.page = 1;
    }

    const dateInput = document.getElementById("sessionDateFilter");
    if (dateInput) window.SessionsPage?.datePicker?.setValue(day);
    const clearButton = document.getElementById("clearDateFilterBtn");
    if (clearButton) clearButton.style.display = "inline-flex";

    Filters.openSessions();
  },

  renderPages() {
    const list = document.getElementById("topPagesList");
    if (!list || !this.currentData || !this.currentData.top_pages) return;

    const pages = this.currentData.top_pages;
    if (pages.length === 0) {
      list.innerHTML = `<li class="clean-pill-row empty"><span class="pill-muted">No pageviews recorded yet</span></li>`;
      return;
    }

    list.innerHTML = pages
      .map((p) => {
        const label =
          this.activePageTab === "titles" ? p.title || p.path : p.path;
        const pct = p.percentage || 0;
        const iconSvg = Icons.get("file-text", { size: 14, color: "#64748b" });

        return `
                <li class="${this.rowClass("page", p.path)}">
                    <div class="pill-progress-bg" style="width: ${pct}%;"></div>
                    <div class="pill-left">
                        <span class="pill-title" title="${this.escapeHtml(p.path)}">${this.escapeHtml(label)}</span>
                    </div>
                    <div class="pill-right">
                        ${this.rowActions("page", p.path, label)}
                        <span class="pill-stat">${p.views.toLocaleString()}</span>
                        <span class="pill-pct">${pct}%</span>
                    </div>
                </li>
            `;
      })
      .join("");
  },

  renderReferrers() {
    const list = document.getElementById("topReferrersList");
    if (!list || !this.currentData) return;

    // Detect own domain of active site to exclude any self-referral
    const activeSiteId = (window.App && window.App.currentSiteId) || "";
    const siteObj = (this.currentData?.available_sites || []).find((s) => s.id === activeSiteId);
    const normalize = (d) => (d || "").toLowerCase().replace(/^https?:\/\//, "").replace(/^www\./, "").split("/")[0].split(":")[0].trim();
    const ownDomain = normalize(siteObj?.domain || "");

    const filtered = (this.currentData.top_referrers || []).filter((r) => {
      if (!ownDomain) return true;
      if (!r.domain || r.domain === "direct" || r.domain === "Direct / None") return true;
      const refDom = normalize(r.domain);
      return refDom !== ownDomain;
    });

    // Merge raw referrer URLs sharing the same domain into a single row so each
    // row maps to exactly one filter value.
    const byDomain = new Map();
    filtered.forEach((r) => {
      const isDirect = !r.domain || r.domain === "direct" || r.domain === "Direct / None";
      const key = isDirect ? "direct" : r.domain;
      const existing = byDomain.get(key);
      if (existing) {
        existing.visits += r.visits || 0;
        existing.percentage = Math.round((existing.percentage + (r.percentage || 0)) * 10) / 10;
      } else {
        byDomain.set(key, { ...r, domain: key, visits: r.visits || 0, percentage: r.percentage || 0 });
      }
    });
    const referrers = [...byDomain.values()].sort((a, b) => b.visits - a.visits);

    if (referrers.length === 0) {
      list.innerHTML = `<li class="clean-pill-row empty"><span class="pill-muted">No referrer data recorded yet</span></li>`;
      return;
    }

    list.innerHTML = referrers
      .map((r) => {
        const isDirect = r.domain === "direct";
        const domain = isDirect ? "Direct / None" : r.domain;
        const pct = r.percentage || 0;

        const iconHtml = isDirect
          ? `<span class="pill-icon-direct">${Icons.get("compass", { size: 15, color: "#64748b" })}</span>`
          : `<img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=32" 
                        class="pill-favicon" 
                        alt="" 
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';" />
                   <span class="pill-icon-fallback" style="display:none;">${Icons.get("globe", { size: 15, color: "#64748b" })}</span>`;

        return `
                <li class="${this.rowClass("referrer", r.domain)}">
                    <div class="pill-progress-bg" style="width: ${pct}%;"></div>
                    <div class="pill-left">
                        ${iconHtml}
                        <span class="pill-title" title="${this.escapeHtml(isDirect ? domain : r.domain)}">${this.escapeHtml(domain)}</span>
                    </div>
                    <div class="pill-right">
                        ${this.rowActions("referrer", r.domain, domain)}
                        <span class="pill-stat">${r.visits.toLocaleString()}</span>
                        <span class="pill-pct">${pct}%</span>
                    </div>
                </li>
            `;
      })
      .join("");
  },

  renderEnvironment() {
    const list = document.getElementById("envList");
    if (!list || !this.currentData || !this.currentData.environment) return;

    const env = this.currentData.environment;
    const items = env[this.activeEnvTab] || [];

    if (items.length === 0) {
      list.innerHTML = `<li class="clean-pill-row empty"><span class="pill-muted">No data recorded yet</span></li>`;
      return;
    }

    list.innerHTML = items
      .map((item) => {
        let iconSvg = "";
        if (this.activeEnvTab === "browsers") {
          iconSvg = Icons.getBrowserIcon(item.name, 14);
        } else if (this.activeEnvTab === "os") {
          iconSvg = Icons.getOsIcon(item.name, 14);
        } else {
          iconSvg = Icons.getDeviceIcon(item.name, 14);
        }

        const pct = item.percentage || 0;
        const dim = { browsers: "browser", os: "os", devices: "device" }[this.activeEnvTab] || "browser";
        return `
                <li class="${this.rowClass(dim, item.name)}">
                    <div class="pill-progress-bg" style="width: ${pct}%;"></div>
                    <div class="pill-left">
                        <span class="pill-icon-box">${iconSvg}</span>
                        <span class="pill-title">${this.escapeHtml(item.name)}</span>
                    </div>
                    <div class="pill-right">
                        ${this.rowActions(dim, item.name)}
                        <span class="pill-stat">${item.count.toLocaleString()}</span>
                        <span class="pill-pct">${pct}%</span>
                    </div>
                </li>
            `;
      })
      .join("");
  },

  inconsistencies: ['no_languages','no_screen','no_chrome_object','mobile_without_touch','platform_mismatch'],
  reasonLabels: {known_bot_user_agent:'Known bot or crawler', missing_user_agent:'Missing user agent', internal_traffic:'Internal traffic', webdriver:'Automated browser'},
  reasonLabel(reason) { if(reason==='client:no_interaction') return 'Left without interacting'; if(String(reason).startsWith('client:')) { const detail=String(reason).slice(7).replace(/^[_$]+/,''); return (this.inconsistencies.includes(detail)?'Inconsistent browser (':'Automated browser (')+detail.replace(/_/g,' ')+')'; } return this.reasonLabels[reason] || String(reason).replace(/_/g,' ').replace(/^./,c=>c.toUpperCase()); },
  bars(rows, key, label) {
    const top=Math.max(1,...rows.map(r=>r.count));
    return rows.length?rows.map(r=>`<div class="bot-bar"><div class="bot-bar-fill" style="width:${Math.round(r.count/top*100)}%"></div><span class="bot-bar-label" title="${this.escapeHtml(r[key])}">${this.escapeHtml(label?label(r[key]):r[key])}</span><strong>${r.count}</strong></div>`).join(''):'<span class="pill-muted">No data</span>';
  },
  toggleBotRequests(button) {
    const open = button.getAttribute("aria-expanded") !== "true";
    button.setAttribute("aria-expanded", String(open));
    button.querySelector("svg")?.replaceWith(
      new DOMParser().parseFromString(Icons.get(open ? "chevron-down" : "chevron-right", { size: 14 }), "text/html").body.firstChild,
    );
    button.nextElementSibling.hidden = !open;
  },
  // Robot categories, as grouped by KnownBots on the server.
  botCategories: [
    { key: "search", label: "Search engines", icon: "search" },
    { key: "automated", label: "Automated browsers", icon: "bot" },
    { key: "other", label: "Other robots", icon: "globe" },
  ],
  // What the side panel lists for the selected category: its robots or why they were blocked.
  activeBotSide: "robots",
  activeBotTab: null,
  // Robots plotted on the chart, the most frequent of the selected category.
  BOT_LINES: 5,
  BOT_PALETTE: ["#2563eb", "#f59e0b", "#10b981", "#ec4899", "#8b5cf6"],

  renderBots(bots) {
    const card=document.querySelector('.bot-card'); if(card) card.hidden=!bots;
    if(!bots) return;
    this.currentBots = bots;
    const total=bots.total||0, recent=(bots.recent||[]).slice(0,10);
    document.getElementById('botCount').textContent=`${total} detection${total===1?'':'s'}`;
    const robots=total-(bots.internal||0)-(bots.left||0);
    document.getElementById('botTabs').hidden=!robots;
    card.querySelector('.bot-explorer').hidden=!robots;
    const panel=document.getElementById('botsList');
    if(!total){ panel.innerHTML='<span class="pill-muted">No detected bot traffic for this period.</span>'; return; }
    if(robots) {
      const counts=Object.fromEntries((bots.categories||[]).map(c=>[c.category,c.count]));
      if(!counts[this.activeBotTab]) this.activeBotTab=[...(bots.categories||[])].sort((a,b)=>b.count-a.count)[0]?.category;
      this.renderBotTabs();
      this.renderBotCategory();
    }
    // What the tabs leave out: people kept out of the statistics, and the websites when there are several.
    const notes=[['Internal traffic',bots.internal],['Left without interacting',bots.left]].filter(([,count])=>count).map(([label,count])=>`<span>${label} <strong>${count.toLocaleString()}</strong></span>`);
    const origins=bots.origins||[];
    if(origins.length>1) notes.push(`<span>Websites: ${origins.map(o=>`${this.escapeHtml(o.origin)} <strong>${o.count.toLocaleString()}</strong>`).join(' · ')}</span>`);
    panel.innerHTML=`${notes.length?`<div class="bot-notes">${robots?'Not robots: ':''}${notes.join('')}</div>`:''}
      <button type="button" class="bot-latest-toggle" aria-expanded="false">${Icons.get("chevron-right",{size:14})}<span>Latest requests</span></button><div class="bot-list" hidden>${recent.map(b=>this.botRequestRow(b)).join('')}</div>`;
    panel.querySelector('.bot-latest-toggle').addEventListener('click', e => this.toggleBotRequests(e.currentTarget));
  },

  /** One tab per category with requests in this period, the busiest first. */
  renderBotTabs() {
    const categories=[...(this.currentBots?.categories||[])].filter(c=>c.count).sort((a,b)=>b.count-a.count);
    document.getElementById('botTabs').innerHTML=categories.map(c=>{ const k=this.botCategories.find(k=>k.key===c.category); return `<button type="button" class="chart-toggle-btn${c.category===this.activeBotTab?' active':''}" data-tab="${c.category}">${Icons.get(k?.icon||'bot',{size:14})}${k?.label||c.category}<span class="bot-tab-count">${c.count.toLocaleString()}</span></button>`; }).join('');
  },

  /** The selected category: one line per robot on the chart, its robots and why they were blocked. */
  renderBotCategory() {
    const bots=this.currentBots, category=(bots?.categories||[]).find(c=>c.category===this.activeBotTab);
    if(!bots||!category) return;
    const robots=(bots.bots||[]).filter(b=>b.category===category.category);
    const plotted=robots.slice(0,this.BOT_LINES);
    const top=Math.max(1,...robots.map(b=>b.count));

    document.getElementById('botRobots').innerHTML=robots.map((b,i)=>`<div class="bot-bar" title="${this.escapeHtml(b.operator?`${b.name} · ${b.operator}`:b.name)}"><div class="bot-bar-fill" style="width:${Math.round(b.count/top*100)}%"></div><span class="bot-bar-label">${this.botIcon(b)}<span class="bot-bar-name">${this.escapeHtml(b.name)}</span>${b.operator&&b.operator!==b.name?`<span class="bot-operator">${this.escapeHtml(b.operator)}</span>`:''}</span><span class="bot-bar-count">${i<plotted.length?`<span class="metric-color-dot" style="background:${this.BOT_PALETTE[i]}"></span>`:''}<strong>${b.count.toLocaleString()}</strong></span></div>`).join('');
    document.getElementById('botReasons').innerHTML=this.botReasons(category.reasons||[]);
    this.renderBotSide();

    // Bucket the hourly counts into the main chart's slots.
    const points=this.localizeHourlyChartLabels(this.currentData?.timeseries||[]).map(p=>({timestamp:p.timestamp,interval_hours:p.interval_hours,label:p.label,date_label:p.date_label,full_label:p.full_label}));
    const keys=new Map(plotted.map((b,i)=>[b.name,`bot${i}`]));
    points.forEach(p=>keys.forEach(key=>{ p[key]=0; }));
    for(const s of bots.series||[]) {
      const key=s.category===category.category&&keys.get(s.name);
      if(!key) continue;
      let slot=-1;
      while(slot+1<points.length&&Number(points[slot+1].timestamp)<=s.time) slot++;
      if(slot>=0) points[slot][key]+=s.count;
    }
    this.botsChart.seriesConfig=Object.fromEntries(plotted.map((b,i)=>[`bot${i}`,{key:`bot${i}`,label:b.name,singular:'request',color:this.BOT_PALETTE[i],gradientStart:'rgba(255, 255, 255, 0)',gradientEnd:'rgba(255, 255, 255, 0)',lineWidth:1.75}]));
    this.botsChart.setData(points,[...keys.values()]);
  },

  // What each rejection reason means, in a few words.
  reasonHints: {
    known_bot_user_agent: ['Bot user agent', 'Its user agent names a crawler or a script.'],
    internal_traffic: ['Internal traffic', 'A visit from one of your internal IP addresses.'],
    'client:no_interaction': ['Left without interacting', 'Left in under 3 seconds without moving, scrolling or typing.'],
    missing_user_agent: ['No user agent', 'The request did not say which browser sent it.'],
    'client:webdriver': ['Webdriver', 'The browser says it is driven by a script.'],
    'client:no_screen': ['No screen', 'The browser reports a 0×0 screen.'],
    'client:no_languages': ['No languages', 'The browser reports no language at all.'],
    'client:no_chrome_object': ['Fake Chrome', 'A desktop Chrome user agent without Chrome itself.'],
    'client:mobile_without_touch': ['Phone without touch', 'A phone user agent on a device without touch.'],
    'client:platform_mismatch': ['Wrong platform', 'The user agent and the system it runs on disagree.'],
  },
  reasonHint(reason) {
    if(this.reasonHints[reason]) return this.reasonHints[reason];
    const tool=String(reason).replace(/^client:/,'').replace(/^[_$]+/,'').replace(/_/g,' ');
    return [tool.replace(/^./,c=>c.toUpperCase()), `The page found traces of an automation tool (${tool}).`];
  },

  /** The reasons of the selected category, each with what it means. */
  botReasons(reasons) {
    const top=Math.max(1,...reasons.map(r=>r.count));
    return reasons.map(r=>{
      const [title,hint]=this.reasonHint(r.reason);
      return `<div class="bot-reason">
        <span class="bot-reason-text"><strong>${this.escapeHtml(title)}</strong><small>${this.escapeHtml(hint)}</small></span>
        <span class="bot-reason-count">${r.count.toLocaleString()}</span>
        <span class="bot-reason-meter"><span style="width:${Math.round(r.count/top*100)}%"></span></span>
      </div>`;
    }).join('');
  },

  countryName(code) {
    try { this.regionNames ??= new Intl.DisplayNames('en',{type:'region'}); return this.regionNames.of(code)||code; } catch { return code; }
  },

  /** A rejected request: who sent it and why, its user agent, country and time. */
  botRequestRow(b) {
    const name=b.bot?b.bot.name:(b.reason==='internal_traffic'?'Internal traffic':'Visitor');
    const country=b.country_code?`${Icons.getCountryFlag(b.country_code,{size:14})}<span>${this.escapeHtml(this.countryName(b.country_code))}</span>`:'<span class="pill-muted">Unknown</span>';
    return `<div class="bot-row">
      <div class="bot-row-who"><strong>${this.escapeHtml(name)}</strong><small>${this.escapeHtml(this.reasonHint(b.reason)[0])}</small></div>
      <code class="bot-row-agent" title="${this.escapeHtml(b.user_agent||'')}">${this.escapeHtml(b.user_agent||'No user agent')}</code>
      <div class="bot-row-country">${country}</div>
      <time>${this.escapeHtml(b.timestamp)}</time>
    </div>`;
  },

  renderBotSide() {
    document.querySelectorAll('#botSideTabs .chart-toggle-btn').forEach(btn=>btn.classList.toggle('active',btn.dataset.side===this.activeBotSide));
    document.getElementById('botRobots').hidden=this.activeBotSide!=='robots';
    document.getElementById('botReasons').hidden=this.activeBotSide!=='reasons';
  },

  botIcon(bot) {
    const fallback=Icons.get("bot",{size:15,color:"#64748b"});
    if(bot.domain) return `<img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(bot.domain)}&sz=32" class="pill-favicon" alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';" /><span class="pill-icon-fallback" style="display:none;">${fallback}</span>`;
    if(bot.browser) return `<span class="pill-icon-box">${Icons.getBrowserIcon(bot.browser,15)}</span>`;
    return `<span class="pill-icon-box">${fallback}</span>`;
  },

  renderCountries() {
    const list = document.getElementById("countriesList");
    if (!list || !this.currentData) return;

    const countries = this.currentData.countries || [];
    if (countries.length === 0) {
      list.innerHTML = `<li class="clean-pill-row empty"><span class="pill-muted">No country data recorded yet</span></li>`;
      return;
    }

    list.innerHTML = countries
      .map((c) => {
        const code = c.code || "UN";
        const pct = c.percentage || 0;
        return `
                <li class="${this.rowClass("country", c.name)}">
                    <div class="pill-progress-bg" style="width: ${pct}%;"></div>
                    <div class="pill-left">
                        ${Icons.getCountryFlag(code, { size: 14 })}
                        <span class="pill-title">${this.escapeHtml(c.name)}</span>
                        <span class="country-sub-code">${this.escapeHtml(code)}</span>
                    </div>
                    <div class="pill-right">
                        ${this.rowActions("country", c.name)}
                        <span class="pill-stat">${c.count.toLocaleString()}</span>
                        <span class="pill-pct">${pct}%</span>
                    </div>
                </li>
            `;
      })
      .join("");
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

window.OverviewPage = OverviewPage;
