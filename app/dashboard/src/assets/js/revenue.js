const RevenuePage = {
  labels: { channels: "Channels", sources: "Sources", campaigns: "Campaigns", landing_pages: "Landing pages", countries: "Countries", events: "Events" },
  currency: null,
  requestId: 0,

  init() {
    this.chart = new PetitstatsChart("revenueTrendChart", "revenueTrendTooltip", {
      activeSeries: ["revenue"],
      seriesConfig: {
        revenue: {
          key: "revenue", label: "Revenue", singular: "revenue", color: "#10b981",
          gradientStart: "rgba(16, 185, 129, 0.22)", gradientEnd: "rgba(16, 185, 129, 0.02)", lineWidth: 2.75,
          format: (value) => this.money(value),
        },
      },
    });
  },

  esc(v) { const n = document.createElement("span"); n.textContent = v ?? ""; return n.innerHTML; },

  /** Amount in a currency (the website's by default), or a plain number without one. */
  money(value, currency = this.currency) {
    const options = { minimumFractionDigits: 2, maximumFractionDigits: 2 };
    try {
      if (currency) return Number(value).toLocaleString("en-US", { style: "currency", currency, ...options });
    } catch (e) {}
    return Number(value).toLocaleString("en-US", options);
  },

  async load(range, siteId, customDates) {
    const requestId = ++this.requestId;
    const reports = document.getElementById("revenueReports");
    try {
      const data = await Api.getRevenue(range, siteId, customDates);
      if (requestId !== this.requestId) return;
      this.currency = data.currency;
      const summary = data.summary || {};
      const empty = !summary.orders && !summary.previous?.orders;
      document.getElementById("revenueSetup").hidden = !empty;
      document.getElementById("revenueContent").hidden = empty;
      if (empty) return;
      this.renderKpis(summary);
      this.renderCurrencyNote(data.other_currencies || []);
      this.chart?.setData(data.timeseries || []);
      reports.innerHTML = `
        <section class="acquisition-section"><div class="acquisition-section-title"><h2>Where the money comes from</h2><p>Channel, source and campaign of the session each order was made in</p></div>
          <div class="acquisition-grid acquisition-grid--three">${["channels", "sources", "campaigns"].map((key) => this.card(key, data.breakdowns?.[key])).join("")}</div></section>
        <section class="acquisition-section"><div class="acquisition-section-title"><h2>What sells</h2><p>Landing pages, countries and events behind each order</p></div>
          <div class="acquisition-grid acquisition-grid--three">${["landing_pages", "countries", "events"].map((key) => this.card(key, data.breakdowns?.[key])).join("")}</div></section>`;
      this.renderLatest(data.latest || []);
    } catch (e) {
      if (requestId === this.requestId && reports) reports.innerHTML = `<p class="settings-error">${this.esc(e.message)}</p>`;
    }
  },

  renderKpis(summary) {
    const previous = summary.previous;
    const delta = (key) => window.EventsPage ? EventsPage.deltaBadge(summary[key], previous ? previous[key] : null) : "";
    const metrics = [
      { label: "Revenue", value: this.money(summary.revenue), key: "revenue" },
      { label: "Orders", value: Number(summary.orders).toLocaleString(), key: "orders" },
      { label: "Average order", value: this.money(summary.average_order), key: "average_order" },
      { label: "Conversion rate", value: `${Number(summary.conversion_rate).toLocaleString("en-US")}%`, key: "conversion_rate", title: `${summary.buyers} of ${summary.visitors} visitors bought something` },
      { label: "Revenue per visitor", value: this.money(summary.revenue_per_visitor), key: "revenue_per_visitor" },
    ];
    document.getElementById("revenueKpis").innerHTML = metrics.map((metric) => `
      <div class="event-insight"${metric.title ? ` title="${this.esc(metric.title)}"` : ""}>
        <span class="event-insight-label">${metric.label}</span>
        <div class="event-insight-metric"><strong class="event-insight-value">${this.esc(metric.value)}</strong>${delta(metric.key)}</div>
      </div>`).join("");
  },

  renderCurrencyNote(others) {
    const note = document.getElementById("revenueCurrencyNote");
    note.hidden = !others.length;
    note.textContent = others.length
      ? `Amounts are in ${this.currency}. Not counted: ${others.map((other) => `${other.orders.toLocaleString()} ${other.orders === 1 ? "order" : "orders"} in ${other.currency}`).join(", ")}.`
      : "";
  },

  card(key, rows = []) {
    rows = rows.slice(0, 8);
    const top = Math.max(1, ...rows.map((row) => row.revenue));
    const total = rows.reduce((sum, row) => sum + row.orders, 0);
    const items = rows.length
      ? rows.map((row) => `<li class="acquisition-row"><div class="acquisition-row-main"><span class="acquisition-bar" style="--bar:${Math.max(4, Math.round(row.revenue / top * 100))}%"></span><span class="acquisition-name" title="${this.esc(row.name)}">${this.esc(row.name)}</span></div><span class="revenue-row-value"><strong>${this.money(row.revenue)}</strong><small>${row.orders.toLocaleString()} ${row.orders === 1 ? "order" : "orders"}</small></span></li>`).join("")
      : '<li class="acquisition-empty">No orders in this period</li>';
    return `<section class="acquisition-card revenue-card"><div class="acquisition-card-head"><h3>${this.labels[key]}</h3><span class="acquisition-total">${total.toLocaleString()}<small>orders</small></span></div><ul class="acquisition-list">${items}</ul></section>`;
  },

  renderLatest(orders) {
    const body = document.getElementById("revenueLatestBody");
    body.innerHTML = orders.length
      ? orders.map((order) => {
        const date = new Date(`${order.timestamp.replace(" ", "T")}Z`);
        const when = `${date.toLocaleDateString("en-US", { month: "short", day: "numeric" })} · ${TimeFormat.time(date)}`;
        return `<tr data-session="${this.esc(order.session_id)}" title="Open this visit"><td>${this.esc(when)}</td><td>${this.esc(order.event)}</td><td>${order.order_id ? this.esc(order.order_id) : '<span class="revenue-muted">—</span>'}</td><td>${this.esc(order.path)}</td><td class="num"><strong>${this.money(order.revenue)}</strong></td></tr>`;
      }).join("")
      : '<tr><td colspan="5" class="empty-state">No orders in this period</td></tr>';
    body.querySelectorAll("tr[data-session]").forEach((row) => row.addEventListener("click", () => this.openSession(row.dataset.session)));
  },

  /** Opens the visit an order was made in, on the Sessions page. */
  openSession(sessionId) {
    const url = new URL(window.location);
    url.searchParams.set("session_id", sessionId);
    url.hash = "sessions";
    window.history.pushState({}, "", url);
    window.App?.handleRoute();
  },
};
window.RevenuePage = RevenuePage;
