<div class="page-view" id="page-revenue">
  <div class="acquisition-hero">
    <div><h1>Revenue</h1><p>How much your website earns, and which channels, campaigns and pages bring the money in.</p></div>
  </div>

  <!-- Shown until the website sends an event with petitstats.revenue() -->
  <div class="revenue-setup" id="revenueSetup" hidden>
    <h2>No revenue in this period</h2>
    <p>Call <code>petitstats.revenue</code> when someone pays. Give it an <code>order_id</code> so that reloading the confirmation page does not count the order twice.</p>
    <pre><code>petitstats.revenue("purchase", 49.90, { currency: "EUR", order_id: "1042" });</code></pre>
    <a href="<?= \Petitstats\Http\Docs::url('tracking', 'measure-revenue') ?>" target="_blank" rel="noopener">How to measure revenue ↗</a>
  </div>

  <div id="revenueContent">
    <div class="event-insights-strip revenue-kpis" id="revenueKpis" aria-live="polite"></div>
    <p class="revenue-note" id="revenueCurrencyNote" hidden></p>
    <div class="chart-container-card full-width-chart revenue-trend-card">
      <div class="chart-header">
        <div><span class="chart-eyebrow">REVENUE TREND</span></div>
      </div>
      <div class="canvas-wrapper">
        <div id="revenueTrendChart" class="chart-canvas"></div>
        <div id="revenueTrendTooltip" class="chart-tooltip"></div>
      </div>
    </div>
    <div id="revenueReports"></div>
    <div class="data-table-card revenue-latest-card">
      <div class="events-card-header"><span class="events-card-title">Latest orders</span></div>
      <div class="table-responsive">
        <table class="data-table">
          <thead><tr><th>Time</th><th>Event</th><th>Order</th><th>Page</th><th class="num">Amount</th></tr></thead>
          <tbody id="revenueLatestBody"><tr><td colspan="5" class="empty-state">Loading orders…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
