/**
 * Petitstats shared dashboard filters
 *
 * One filter state shared by the Overview and Sessions pages, persisted per
 * website in sessionStorage. Values of the same dimension are OR-ed, different
 * dimensions are AND-ed (this mirrors src/Analytics/AnalyticsFilters.php).
 */

const Filters = {
  DIMENSIONS: {
    page: { label: "Page", icon: "file-text" },
    referrer: { label: "Referrer", icon: "link" },
    browser: { label: "Browser", icon: "globe" },
    os: { label: "OS", icon: "monitor" },
    device: { label: "Device", icon: "smartphone" },
    country: { label: "Country", icon: "globe" },
  },

  state: {},
  siteId: null,
  listeners: new Set(),

  /** Switch to the filter set of a website (restores it from sessionStorage). */
  useSite(siteId) {
    if (!siteId || siteId === this.siteId) return;
    this.siteId = siteId;
    let saved = {};
    try {
      saved = JSON.parse(sessionStorage.getItem(this.storageKey()) || "{}") || {};
    } catch (e) {
      saved = {};
    }
    this.state = {};
    Object.keys(this.DIMENSIONS).forEach((dim) => {
      if (Array.isArray(saved[dim]) && saved[dim].length) {
        this.state[dim] = saved[dim].map(String);
      }
    });
  },

  storageKey() {
    return `petitstats_filters_${this.siteId || "default"}`;
  },

  persist() {
    try {
      sessionStorage.setItem(this.storageKey(), JSON.stringify(this.state));
    } catch (e) {}
  },

  values(dim) {
    return this.state[dim] || [];
  },

  has(dim, value) {
    return this.values(dim).includes(String(value));
  },

  isEmpty() {
    return this.count() === 0;
  },

  count() {
    return Object.values(this.state).reduce((n, vals) => n + vals.length, 0);
  },

  add(dim, value, { silent = false } = {}) {
    if (!this.DIMENSIONS[dim] || this.has(dim, value)) return;
    this.state[dim] = [...this.values(dim), String(value)];
    if (!silent) this.commit();
  },

  remove(dim, value) {
    const next = this.values(dim).filter((v) => v !== String(value));
    if (next.length) this.state[dim] = next;
    else delete this.state[dim];
    this.commit();
  },

  toggle(dim, value) {
    if (this.has(dim, value)) this.remove(dim, value);
    else this.add(dim, value);
  },

  clear() {
    if (this.isEmpty()) return;
    this.state = {};
    this.commit();
  },

  commit() {
    this.persist();
    this.listeners.forEach((fn) => {
      try {
        fn(this.state);
      } catch (e) {
        console.error(e);
      }
    });
  },

  onChange(fn) {
    this.listeners.add(fn);
  },

  /** Adds `filter_<dim>[]` params understood by stats.php / sessions.php. */
  appendTo(params) {
    Object.entries(this.state).forEach(([dim, vals]) => {
      vals.forEach((v) => params.append(`filter_${dim}[]`, v));
    });
    return params;
  },

  displayValue(dim, value) {
    if (dim === "referrer" && (value === "direct" || !value)) return "Direct / None";
    return value;
  },

  /**
   * Renders the active filters bar into `container`.
   * @param {HTMLElement} container
   * @param {{ sessionsCount?: number|null, showSessionsLink?: boolean }} options
   */
  renderBar(container, { sessionsCount = null, showSessionsLink = false } = {}) {
    if (!container) return;
    if (this.isEmpty()) {
      container.hidden = true;
      container.innerHTML = "";
      return;
    }

    const groups = Object.keys(this.DIMENSIONS)
      .filter((dim) => this.values(dim).length)
      .map((dim) => {
        const meta = this.DIMENSIONS[dim];
        const chips = this.values(dim)
          .map(
            (v) => `
              <span class="filter-chip">
                <span class="filter-chip-value" title="${this.escape(v)}">${this.escape(this.displayValue(dim, v))}</span>
                <button type="button" class="filter-chip-remove" data-filter-remove="${dim}" data-filter-value="${this.escape(v)}" title="Remove this filter" aria-label="Remove filter">
                  ${Icons.get("x", { size: 11, strokeWidth: 2.5 })}
                </button>
              </span>`,
          )
          .join(`<span class="filter-chip-joiner">or</span>`);
        return `
          <div class="filter-group">
            <span class="filter-group-label">${Icons.get(meta.icon, { size: 12 })}${meta.label}</span>
            ${chips}
          </div>`;
      })
      .join(`<span class="filter-group-joiner">and</span>`);

    const sessionsLabel =
      typeof sessionsCount === "number"
        ? `View ${sessionsCount.toLocaleString()} session${sessionsCount === 1 ? "" : "s"}`
        : "View sessions";

    container.innerHTML = `
      <div class="active-filters-left">
        <span class="active-filters-title">${Icons.get("filter", { size: 13 })} Filtered</span>
        ${groups}
      </div>
      <div class="active-filters-actions">
        ${
          showSessionsLink
            ? `<button type="button" class="filter-bar-btn primary" data-filter-action="sessions">
                 ${Icons.get("users", { size: 13 })}<span>${sessionsLabel}</span>${Icons.get("arrow-right", { size: 13 })}
               </button>`
            : ""
        }
        <button type="button" class="filter-bar-btn" data-filter-action="clear">Clear all</button>
      </div>`;
    container.hidden = false;

    if (!container.dataset.filtersBound) {
      container.dataset.filtersBound = "1";
      container.addEventListener("click", (e) => {
        const removeBtn = e.target.closest("[data-filter-remove]");
        if (removeBtn) {
          this.remove(removeBtn.dataset.filterRemove, removeBtn.dataset.filterValue);
          return;
        }
        const action = e.target.closest("[data-filter-action]")?.dataset.filterAction;
        if (action === "clear") this.clear();
        if (action === "sessions") this.openSessions();
      });
    }
  },

  /** Navigate to the Sessions page; the active filters are applied there too. */
  openSessions() {
    const url = new URL(window.location);
    if (window.App?.currentSiteId) url.searchParams.set("site", window.App.currentSiteId);
    url.hash = "#sessions";
    window.history.pushState({}, "", url);
    window.SessionsPage?.showListView?.();
    if (window.SessionsPage?.filters) window.SessionsPage.filters.page = 1;
    window.App?.navigateTo("sessions");
    window.scrollTo({ top: 0, behavior: "smooth" });
  },

  escape(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(
      /[&<>"']/g,
      (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[m],
    );
  },
};

window.Filters = Filters;
