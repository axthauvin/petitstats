const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');

function storage(initial = {}) {
  const values = new Map(Object.entries(initial));
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
  };
}

function loadApp(initialStorage) {
  const context = {
    console,
    localStorage: storage(initialStorage),
    sessionStorage: storage(),
    document: { addEventListener() {}, getElementById() { return null; } },
    window: {},
  };
  context.window = context;
  vm.runInNewContext(fs.readFileSync(path.join(root, 'dashboard/src/assets/js/app.js'), 'utf8'), context);
  return context;
}

function loadOverview(initialStorage) {
  const context = { Intl, Date, localStorage: storage(initialStorage), window: {} };
  context.window = context;
  for (const file of ['time-format.js', 'overview.js']) {
    vm.runInNewContext(fs.readFileSync(path.join(root, 'dashboard/src/assets/js', file), 'utf8'), context);
  }
  return context.OverviewPage;
}

test('restores a persisted custom reporting range', () => {
  const context = loadApp({
    petitstats_date_range: JSON.stringify({
      range: 'custom',
      customDates: { from: '2026-10-04', to: '2026-10-01' },
    }),
  });

  context.App.restoreDateRangePreference();
  assert.equal(context.App.currentRange, 'custom');
  assert.deepEqual(JSON.parse(JSON.stringify(context.App.customDates)), {
    from: '2026-10-01', to: '2026-10-04',
  });
});

test('formats hourly overview labels in the browser timezone and the chosen time format', () => {
  const timestamp = 1791216000;
  const date = new Date(timestamp * 1000);
  const label = (format) => loadOverview({ petitstats_time_format: format }).localizeHourlyChartLabels([
    { timestamp, interval_hours: 1, label: '12 AM', full_label: 'UTC label' },
  ])[0];

  assert.equal(label('12h').label, date.toLocaleTimeString('en-US', { hour: 'numeric', hourCycle: 'h12' }));
  assert.equal(label('24h').label, `${String(date.getHours()).padStart(2, '0')}:00`);
  assert.notEqual(label('24h').full_label, 'UTC label');
});

test('does not relabel daily overview buckets', () => {
  const overview = loadOverview();
  const data = [{ timestamp: 1791216000, interval_hours: 24, label: 'Oct 05' }];
  assert.equal(overview.localizeHourlyChartLabels(data), data);
});

function loadEvents() {
  const elements = new Map();
  const getEl = (id) => {
    if (!elements.has(id)) {
      elements.set(id, {
        id,
        innerHTML: '',
        textContent: '',
        style: {},
      });
    }
    return elements.get(id);
  };
  const context = {
    Intl,
    Date,
    window: { innerWidth: 360, addEventListener() {} },
    document: {
      addEventListener() {},
      getElementById: (id) => getEl(id),
      body: { appendChild() {} },
    },
    Icons: {
      get: () => '',
      getCountryFlag: () => '',
      getBrowserIcon: () => '',
      getOsIcon: () => '',
    },
  };
  context.window.document = context.document;
  context.window.Icons = context.Icons;
  vm.runInNewContext(fs.readFileSync(path.join(root, 'dashboard/src/assets/js/events.js'), 'utf8'), context);
  return { EventsPage: context.window.EventsPage, getEl, context };
}

test('events key figures compare the selection with the previous period', () => {
  const { EventsPage, getEl } = loadEvents();
  const types = [{ name: 'CV Upload', count: 120 }];
  EventsPage.renderInsights({ events: 120, visitors: 40, sessions: 50, previous: { events: 100, visitors: 50, sessions: 50 } }, types);
  const html = getEl('eventInsightsStrip').innerHTML;

  for (const label of ['Events', 'Unique visitors', 'Sessions', 'Event types']) {
    assert.ok(html.includes(label), `must include ${label}`);
  }
  assert.ok(html.includes('event-delta-up" title="vs previous period">+20%'), 'events grew by 20%');
  assert.ok(html.includes('event-delta-down" title="vs previous period">-20%'), 'visitors dropped by 20%');
  assert.ok(html.includes('class="event-delta">0%'), 'sessions did not change');

  EventsPage.selected = new Set(['CV Upload']);
  EventsPage.renderInsights({ events: 120, visitors: 40, sessions: 50, previous: null }, types);
  const focused = getEl('eventInsightsStrip').innerHTML;
  assert.ok(focused.includes('Events per visitor') && focused.includes('3.0'), 'a selection shows events per visitor');
  assert.ok(!focused.includes('event-delta'), 'all-time ranges have nothing to compare with');
});

test('clicking events focuses on one, then adds and removes others', () => {
  const { EventsPage } = loadEvents();
  EventsPage.load = () => {};

  EventsPage.toggleEvent('signup');
  assert.deepEqual([...EventsPage.selected], ['signup']);
  EventsPage.toggleEvent('purchase');
  assert.deepEqual([...EventsPage.selected], ['signup', 'purchase']);
  assert.equal(EventsPage.selectionTitle(), 'signup + 1 more');
  EventsPage.toggleEvent('signup');
  EventsPage.toggleEvent('purchase');
  assert.equal(EventsPage.selected.size, 0, 'deselecting the last event shows every event again');
  assert.equal(EventsPage.selectionTitle(), 'All events');
});

test('events breakdown highlights the selection and computes shares', () => {
  const { EventsPage, getEl } = loadEvents();
  EventsPage.types = [
    { name: 'signup', count: 75, visitors: 30, previous_count: 50 },
    { name: 'purchase', count: 25, visitors: 10, previous_count: 0 },
  ];
  EventsPage.selected = new Set(['purchase']);
  EventsPage.renderBreakdown();
  const html = getEl('eventsBreakdownBody').innerHTML;

  assert.match(html, /data-event="signup" class="events-breakdown-row is-dimmed"/);
  assert.match(html, /data-event="purchase" class="events-breakdown-row is-selected is-last-pinned"/);
  assert.ok(html.indexOf('"purchase"') < html.indexOf('"signup"'), 'the selected event moves above more frequent ones');
  assert.ok(html.includes('75.0%') && html.includes('25.0%'), 'shares of all events');
  assert.ok(html.includes('+50%') && html.includes('>New<'), 'change vs previous period');

  EventsPage.selected = new Set();
  EventsPage.breakdownQuery = 'zzz';
  EventsPage.renderBreakdown();
  assert.ok(getEl('eventsBreakdownBody').innerHTML.includes('No matching events'));
});

test('selected events are pinned on top, even beyond the row limit or the search', () => {
  const { EventsPage, getEl } = loadEvents();
  EventsPage.types = Array.from({ length: 30 }, (_, i) => ({ name: `event_${i}`, count: 100 - i, visitors: 1 }));
  EventsPage.selected = new Set(['event_25']);
  EventsPage.breakdownQuery = 'event_1';
  EventsPage.renderBreakdown();
  const rows = [...getEl('eventsBreakdownBody').innerHTML.matchAll(/data-event="([^"]+)"/g)].map((m) => m[1]);

  assert.equal(rows[0], 'event_25', 'the selection comes first');
  assert.ok(rows.slice(1).every((name) => name.startsWith('event_1')), 'then the other events matching the search');
  assert.match(getEl('eventsBreakdownFooter').textContent, /^1 selected · showing 10 of 11 other events$/);
});

test('selected events never share a color, even when far apart in the ranking', () => {
  const { EventsPage } = loadEvents();
  EventsPage.types = Array.from({ length: 40 }, (_, i) => ({ name: `event_${i}`, count: 100 - i }));
  assert.equal(EventsPage.colorFor('event_20'), EventsPage.colorFor('event_30'), 'the palette cycles every 10 events');
  EventsPage.selected = new Set(['event_30', 'event_20']);
  assert.notEqual(EventsPage.colorFor('event_20'), EventsPage.colorFor('event_30'));
});

test('events timeline does not use inline padding styles', () => {
  const { EventsPage, getEl } = loadEvents();
  const mockEvent = {
    id: 1,
    name: 'custom_event',
    timestamp: '2026-10-05 12:00:00',
    data: { country: 'France', browser: 'Chrome' },
  };
  EventsPage.renderEvents([mockEvent]);
  const html = getEl('eventsListContainer').innerHTML;

  assert.ok(html.includes('class="journey-steps-container"'), 'must have journey-steps-container');
  assert.ok(!html.includes('style="padding: 20px 24px;"'), 'must not hardcode inline padding on journey-steps-container');
});

test('dashboard.css includes responsive breakpoints for events', () => {
  const css = fs.readFileSync(path.join(root, 'dashboard/src/assets/css/dashboard.css'), 'utf8');

  assert.ok(css.includes('.event-insight-metric'), 'must define .event-insight-metric');
  assert.ok(css.includes('grid-template-columns: repeat(4, minmax(0, 1fr))'), 'desktop must be 4 columns');
  assert.ok(css.includes('@media (max-width: 480px)'), 'must include 480px breakpoint');
  assert.ok(css.includes('.journey-step-meta-row'), 'must define .journey-step-meta-row');
  assert.ok(css.includes('flex-wrap: wrap'), 'must include flex-wrap');
});

test('session country badges use a consistent desktop column', () => {
  const css = fs.readFileSync(path.join(root, 'dashboard/src/assets/css/dashboard.css'), 'utf8');

  assert.match(css, /\.session-card-title-row\s*\{\s*display: grid;\s*grid-template-columns: minmax\(260px, 330px\)/);
  assert.match(css, /\.session-badge-country\s*\{[\s\S]*?justify-self: start;/);
});

test('session timeline endpoint labels align with their marker', () => {
  const css = fs.readFileSync(path.join(root, 'dashboard/src/assets/css/dashboard.css'), 'utf8');

  assert.match(css, /\.journey-step-end \.journey-marker-content\s*\{\s*align-self: flex-start;/);
});

