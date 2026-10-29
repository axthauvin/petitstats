const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const hasPhp = spawnSync('php', ['-v']).status === 0;
const PORT = 19000 + Math.floor(Math.random() * 1000);
const BASE = `http://127.0.0.1:${PORT}`;

let workdir;
let server;
let token;
let revokedToken;
let writerToken;

const phpEnv = () => ({ ...process.env, PETITSTATS_DATA_DIR: path.join(workdir, 'data') });

/** Isolated copy of the app with two private websites and one administrator owning two tokens. */
function prepareInstall() {
  workdir = fs.mkdtempSync(path.join(os.tmpdir(), 'petitstats-tokens-'));
  for (const dir of ['dashboard', 'src', 'templates', 'vendor']) {
    fs.cpSync(path.join(root, dir), path.join(workdir, dir), { recursive: true });
  }
  fs.copyFileSync(path.join(root, 'mcp.php'), path.join(workdir, 'mcp.php'));
  const data = path.join(workdir, 'data');
  fs.mkdirSync(data);
  const site = (id) => ({
    id, name: id, domain: 'localhost', write_key: `${id}-secret-key`,
    allowed_domains: ['localhost'], internal_ips: [], retention_days: 395,
  });
  fs.writeFileSync(path.join(data, 'sites.json'), JSON.stringify([site('private_site'), site('other_site')]));
  // One visitor, two visits: s1 converts (pricing then signup), s2 reads the blog and hits upload errors.
  fs.writeFileSync(path.join(workdir, 'activity.json'), JSON.stringify([
    ['s1', 'pageview', { path: '/pricing' }],
    ['s1', 'Signup', { path: '/pricing', plan: 'pro' }],
    ['s2', 'pageview', { path: '/pricing' }],
    ['s2', 'pageview', { path: '/blog/first-post' }],
    ['s2', 'Upload Error', { path: '/upload', reason: 'too big', browser: 'Chrome' }],
    ['s2', 'Upload Error', { path: '/upload', reason: 'too big' }],
    ['s2', 'Upload Error', { path: '/upload', reason: 'wrong type' }],
  ]));
  const dir = workdir.replace(/\\/g, '/');
  const setup = spawnSync('php', ['-r', `
    require '${dir}/vendor/autoload.php';
    Petitstats\\Auth\\AuthDatabase::connection()->exec("INSERT INTO users (email, password, verified, roles_mask, registered) VALUES ('a@b.c', 'x', 1, 1, 0)");
    $db = Petitstats\\Database\\Connections::forSite('private_site');
    $insert = $db->prepare("INSERT INTO user_activity (session_id, visitor_id, action) VALUES (:session, 'v1', :action)");
    foreach (json_decode(file_get_contents('${dir}/activity.json'), true) as [$session, $name, $data]) {
      $insert->bindValue(':session', $session);
      $insert->bindValue(':action', json_encode(['name' => $name, 'data' => $data]));
      $insert->execute();
    }
    $db->exec("CREATE TABLE funnels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'funnel', steps TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $funnel = $db->prepare("INSERT INTO funnels (name, steps) VALUES ('Pricing to signup', :steps)");
    $funnel->bindValue(':steps', json_encode([['type' => 'pageview', 'value' => '/pricing', 'label' => '/pricing'], ['type' => 'event', 'value' => 'Signup', 'label' => 'Signup']]));
    $funnel->execute();
    $revoked = Petitstats\\Auth\\McpTokens::create(1, 'revoked');
    Petitstats\\Auth\\McpTokens::revoke(1, $revoked['id']);
    $writer = Petitstats\\Auth\\McpTokens::create(1, 'writer');
    Petitstats\\Auth\\McpTokens::allowWrite(1, $writer['id'], true);
    echo json_encode([Petitstats\\Auth\\McpTokens::create(1, 'test')['token'], $revoked['token'], $writer['token']]);
  `], { env: phpEnv() });
  assert.equal(setup.status, 0, setup.stdout.toString() + setup.stderr.toString());
  [token, revokedToken, writerToken] = JSON.parse(setup.stdout.toString().trim().split('\n').pop());
}

async function waitForServer() {
  for (let i = 0; i < 50; i++) {
    try { await fetch(`${BASE}/dashboard/login.php`); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('PHP server did not start');
}

const bearer = (value = token) => ({ Authorization: `Bearer ${value}` });
const api = (endpoint, init = {}) => fetch(`${BASE}/dashboard/src/api/${endpoint}`, { redirect: 'manual', ...init });

let rpcId = 0;
async function mcp(method, params, headers = bearer()) {
  const res = await fetch(`${BASE}/mcp.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...headers },
    body: JSON.stringify({ jsonrpc: '2.0', id: ++rpcId, method, params }),
  });
  return { status: res.status, body: await res.json() };
}
async function callTool(name, args = {}, headers = bearer()) {
  const { body } = await mcp('tools/call', { name, arguments: args }, headers);
  const text = body.result.content[0].text;
  return { isError: body.result.isError === true, text, data: body.result.isError ? null : JSON.parse(text) };
}

test.before(async () => {
  if (!hasPhp) return;
  prepareInstall();
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', workdir], { stdio: 'ignore', env: phpEnv() });
  await waitForServer();
});

test.after(() => {
  server?.kill();
  if (workdir) fs.rmSync(workdir, { recursive: true, force: true });
});

test('a token reads the analytics through MCP', { skip: !hasPhp }, async () => {
  const pages = await callTool('get_top_pages', { site_id: 'private_site' });
  assert.equal(pages.data.pages[0].path, '/pricing');
});

test('missing, unknown and revoked tokens are rejected by MCP', { skip: !hasPhp }, async () => {
  for (const headers of [{}, bearer('mlt_not-a-real-token'), bearer(revokedToken)]) {
    const { status } = await mcp('tools/list', {}, headers);
    assert.equal(status, 401);
  }
});

test('a token never opens the dashboard endpoints, writes or administration', { skip: !hasPhp }, async () => {
  const json = { 'Content-Type': 'application/json', ...bearer() };
  const attempts = [
    ...['stats.php', 'events.php', 'sessions.php', 'acquisition.php', 'funnels.php', 'revenue.php'].map((endpoint) => [`${endpoint}?site_id=private_site&range=7d`, { headers: bearer() }]),
    ['funnels.php?site_id=private_site', { method: 'POST', headers: json, body: JSON.stringify({ name: 'x', steps: [] }) }],
    ['sessions.php?site_id=private_site&session_id=s1', { method: 'DELETE', headers: bearer() }],
    ['privacy.php', { method: 'POST', headers: json, body: JSON.stringify({ visitor_id: 'v1' }) }],
    ['users.php', { headers: bearer() }],
    ['sites.php?action=config&id=private_site', { headers: bearer() }],
    ['database.php', { headers: bearer() }],
    ['import.php', { headers: bearer() }],
    ['tokens.php', { headers: bearer() }],
    ['tracking.php', { headers: bearer() }],
    ['version.php', { headers: bearer() }],
    ['changelog.php', { headers: bearer() }],
    ['tokens.php', { method: 'POST', headers: json, body: JSON.stringify({ name: 'escalation' }) }],
  ];
  for (const [endpoint, init] of attempts) {
    const res = await api(endpoint, init);
    assert.ok([401, 403].includes(res.status), `${init.method || 'GET'} ${endpoint} returned ${res.status}`);
  }
});

test('the MCP endpoint requires POST and a valid token', { skip: !hasPhp }, async () => {
  assert.equal((await fetch(`${BASE}/mcp.php`, { headers: bearer() })).status, 405);
  assert.equal((await mcp('tools/list', {}, {})).status, 401);
  assert.equal((await mcp('tools/list', {}, bearer(revokedToken))).status, 401);
});

test('MCP initialize negotiates the protocol and notifications get 202', { skip: !hasPhp }, async () => {
  const { body } = await mcp('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '1' } });
  assert.equal(body.result.protocolVersion, '2025-06-18');
  assert.equal(body.result.serverInfo.name, 'petitstats');
  assert.ok(body.result.capabilities.tools);

  const unknown = await mcp('initialize', { protocolVersion: '1999-01-01' });
  assert.match(unknown.body.result.protocolVersion, /^\d{4}-\d{2}-\d{2}$/);

  const res = await fetch(`${BASE}/mcp.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...bearer() },
    body: JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }),
  });
  assert.equal(res.status, 202);
});

test('MCP lists its tools with their input schema, and none deletes anything', { skip: !hasPhp }, async () => {
  const { body } = await mcp('tools/list', {});
  const names = body.result.tools.map((tool) => tool.name);
  const writers = ['create_funnel', 'update_funnel'];
  for (const name of ['list_sites', 'get_overview', 'get_timeseries', 'get_top_pages', 'get_top_referrers', 'get_top_events', 'get_countries', 'get_environment', 'get_acquisition', 'get_event_details', 'get_funnel', 'get_sessions', 'get_journey', 'get_blocked_traffic', ...writers]) {
    assert.ok(names.includes(name), `missing ${name}`);
  }
  for (const tool of body.result.tools) {
    assert.equal(tool.annotations.readOnlyHint, !writers.includes(tool.name), tool.name);
    if (writers.includes(tool.name)) assert.equal(tool.annotations.destructiveHint, false, tool.name);
    assert.doesNotMatch(tool.name, /delete|remove/);
    assert.equal(tool.inputSchema.type, 'object');
  }
});

test('MCP tools return the site analytics', { skip: !hasPhp }, async () => {
  const sites = await callTool('list_sites');
  assert.deepEqual(sites.data.sites.map((s) => s.site_id).sort(), ['other_site', 'private_site']);

  const overview = await callTool('get_overview', { site_id: 'private_site', range: '30d' });
  assert.equal(overview.data.site_id, 'private_site');
  assert.equal(overview.data.period.range, '30d');
  assert.equal(overview.data.summary.visitors.value, 1);
  assert.equal(overview.data.summary.visitors.previous, 0);
  assert.equal(overview.data.summary.visitors.change, 1);
  assert.equal(overview.data.summary.visitors.change_percent, null);
  assert.equal(overview.data.summary.sessions, undefined);
  assert.equal(overview.data.summary.session_count, undefined);

  const pages = await callTool('get_top_pages', { site_id: 'private_site', limit: 5, filters: { page: ['/pricing'] } });
  assert.equal(pages.data.pages[0].path, '/pricing');

  const filteredOut = await callTool('get_overview', { site_id: 'private_site', filters: { page: ['/nowhere'] } });
  assert.equal(filteredOut.data.summary.visitors.value, 0);

  const devices = await callTool('get_environment', { site_id: 'private_site', dimension: 'device' });
  assert.equal(devices.data.dimension, 'device');

  const channels = await callTool('get_acquisition', { site_id: 'private_site', report: 'channels' });
  assert.equal(channels.data.values[0].name, 'Direct');
});

test('MCP reads individual visits, journeys and blocked traffic', { skip: !hasPhp }, async () => {
  const { data } = await callTool('get_sessions', { site_id: 'private_site' });
  assert.equal(data.visits.length, 2);
  const converted = data.visits.find((visit) => visit.visit_id === 's1');
  assert.equal(converted.visitor_id, 'v1');
  assert.deepEqual(converted.steps.map((step) => step.page && !step.event ? step.page : step.event), ['/pricing', 'Signup']);
  assert.deepEqual(converted.steps[1].properties, { plan: 'pro' });
  assert.doesNotMatch(JSON.stringify(data), /"ip(_address|_hash)?"\s*:/, 'No IP address is ever exposed.');

  const one = await callTool('get_sessions', { site_id: 'private_site', visit_id: 's2' });
  assert.equal(one.data.visit.pageviews, 2);
  assert.equal(one.data.visit.events, 3);

  const journey = await callTool('get_journey', { site_id: 'private_site', entry: { page: '/pricing' }, exit: { event: 'Signup' } });
  assert.equal(journey.data.entered, 2);
  assert.equal(journey.data.completed, 1);
  assert.deepEqual(journey.data.top_paths, [{ steps: ['Event Signup'], visits: 1 }]);

  const blocked = await callTool('get_blocked_traffic', { site_id: 'private_site' });
  assert.equal(blocked.data.total, 0);
  assert.deepEqual(blocked.data.robots, []);
});

test('MCP searches pages and events by name', { skip: !hasPhp }, async () => {
  const pages = await callTool('get_top_pages', { site_id: 'private_site', search: 'BLOG' });
  assert.deepEqual(pages.data.pages.map((page) => page.path), ['/blog/first-post']);

  const events = await callTool('get_top_events', { site_id: 'private_site', search: 'error' });
  assert.deepEqual(events.data.events.map((event) => [event.name, event.count]), [['Upload Error', 3]]);
});

test('MCP details one event and its own properties', { skip: !hasPhp }, async () => {
  const { data } = await callTool('get_event_details', { site_id: 'private_site', event: 'Upload Error' });
  assert.equal(data.occurrences, 3);
  assert.equal(data.sessions, 1);
  assert.deepEqual(data.pages, [{ path: '/upload', occurrences: 3 }]);
  assert.equal(data.trend.reduce((sum, slot) => sum + slot.occurrences, 0), 3);
  assert.deepEqual(data.properties.reason, [{ value: 'too big', count: 2 }, { value: 'wrong type', count: 1 }]);
  // Context every event carries is not one of its properties.
  assert.equal(data.properties.browser, undefined);
  assert.equal(data.properties.path, undefined);

  const focused = await callTool('get_event_details', { site_id: 'private_site', event: 'Upload Error', property: 'reason', limit: 1 });
  assert.deepEqual(Object.keys(focused.data.properties), ['reason']);
  assert.equal(focused.data.properties.reason.length, 1);

  assert.equal((await callTool('get_event_details', { site_id: 'private_site', event: 'Nothing' })).data.occurrences, 0);
  assert.match((await callTool('get_event_details', { site_id: 'private_site' })).text, /event is required/);
});

test('MCP computes funnels from steps or saved funnels', { skip: !hasPhp }, async () => {
  const expected = [['Page /pricing', 2, 100, 100], ['Event Signup', 1, 50, 50]];
  const summary = (data) => data.steps.map((step) => [step.step, step.visits, step.conversion_from_previous, step.conversion_from_first]);

  const adHoc = await callTool('get_funnel', { site_id: 'private_site', steps: [{ page: '/pricing' }, { event: 'Signup' }] });
  assert.deepEqual(summary(adHoc.data), expected);

  const saved = await callTool('get_funnel', { site_id: 'private_site', funnel: 'pricing to SIGNUP' });
  assert.deepEqual(summary(saved.data), expected);
  assert.equal(saved.data.funnel, 'Pricing to signup');

  const listed = await callTool('get_funnel', { site_id: 'private_site' });
  assert.deepEqual(listed.data.saved_funnels, [{ id: 1, name: 'Pricing to signup', kind: 'funnel', steps: ['Page /pricing', 'Event Signup'] }]);

  assert.match((await callTool('get_funnel', { site_id: 'private_site', funnel: 'Nope' })).text, /No saved funnel/);
  assert.match((await callTool('get_funnel', { site_id: 'private_site', steps: [{ page: '/a', event: 'b' }] })).text, /either a page or an event/);
});

test('MCP Apps show charts for the time series and funnels', { skip: !hasPhp }, async () => {
  const uri = 'ui://petitstats/charts.html';
  const { body } = await mcp('tools/list', {});
  const withChart = body.result.tools.filter((tool) => tool._meta?.ui?.resourceUri === uri).map((tool) => tool.name);
  assert.deepEqual(withChart.sort(), ['get_funnel', 'get_timeseries', 'preview_funnel']);

  const listed = await mcp('resources/list', {});
  assert.deepEqual(listed.body.result.resources.map((resource) => [resource.uri, resource.mimeType]), [[uri, 'text/html;profile=mcp-app']]);
  const read = await mcp('resources/read', { uri });
  assert.equal(read.body.result.contents[0].mimeType, 'text/html;profile=mcp-app');
  assert.match(read.body.result.contents[0].text, /ui\/initialize/);
  for (const unknown of ['ui://petitstats/../../mcp.php', 'ui://petitstats/other.html', 'file:///etc/passwd']) {
    assert.equal((await mcp('resources/read', { uri: unknown })).body.error.code, -32002, unknown);
  }

  // The chart reads structured data; tools without a chart only answer text.
  const timeseries = await mcp('tools/call', { name: 'get_timeseries', arguments: { site_id: 'private_site' } });
  assert.deepEqual(timeseries.body.result.structuredContent, JSON.parse(timeseries.body.result.content[0].text));
  // Unique visitors of the period, not the sum of the days.
  assert.deepEqual(timeseries.body.result.structuredContent.totals, { visitors: 1, pageviews: 3 });
  const pages = await mcp('tools/call', { name: 'get_top_pages', arguments: { site_id: 'private_site' } });
  assert.equal(pages.body.result.structuredContent, undefined);
});

test('MCP previews a funnel without saving it', { skip: !hasPhp }, async () => {
  const preview = await callTool('preview_funnel', { site_id: 'private_site', name: 'Pricing then upload', steps: [{ page: '/pricing' }, { event: 'Upload Error' }] });
  assert.deepEqual(preview.data.preview, { id: null, kind: 'funnel', name: 'Pricing then upload', steps: [{ page: '/pricing' }, { event: 'Upload Error' }] });
  assert.deepEqual(preview.data.steps.map((step) => [step.step, step.visits]), [['Page /pricing', 2], ['Event Upload Error', 1]]);

  const edit = await callTool('preview_funnel', { site_id: 'private_site', id: 1, steps: [{ page: '/pricing' }] });
  assert.deepEqual(edit.data.preview, { id: 1, kind: 'funnel', name: 'Pricing to signup', steps: [{ page: '/pricing' }] });

  assert.match((await callTool('preview_funnel', { site_id: 'private_site', name: 'pricing to signup', steps: [{ page: '/' }] })).text, /already named/);
  assert.match((await callTool('preview_funnel', { site_id: 'private_site', kind: 'goal', name: 'Two', steps: [{ page: '/' }, { page: '/a' }] })).text, /exactly one step/);
  assert.match((await callTool('preview_funnel', { site_id: 'private_site', id: 99, name: 'x' })).text, /No funnel/);

  const listed = await callTool('get_funnel', { site_id: 'private_site' });
  assert.ok(!listed.data.saved_funnels.some((funnel) => funnel.name === 'Pricing then upload'), 'A preview saves nothing.');
});

test('MCP filters visits by the events they triggered', { skip: !hasPhp }, async () => {
  const converted = await callTool('get_overview', { site_id: 'private_site', filters: { event: ['Signup'] } });
  assert.equal(converted.data.summary.visits.value, 1);

  const failed = await callTool('get_top_pages', { site_id: 'private_site', filters: { event: ['Upload Error'] } });
  assert.deepEqual(failed.data.pages.map((page) => page.path).sort(), ['/blog/first-post', '/pricing']);
});

test('MCP reports invalid tool arguments to the model', { skip: !hasPhp }, async () => {
  assert.match((await callTool('get_overview')).text, /site_id is required/);
  assert.match((await callTool('get_overview', { site_id: 'nope' })).text, /does not exist/);
  assert.match((await callTool('get_overview', { site_id: 'private_site', range: 'forever' })).text, /range must be one of/);
  assert.match((await callTool('get_environment', { site_id: 'private_site', dimension: 'shoe size' })).text, /dimension must be one of/);
  assert.equal((await callTool('get_overview', { site_id: 'nope' })).isError, true);
});

test('MCP answers protocol errors with JSON-RPC errors', { skip: !hasPhp }, async () => {
  assert.equal((await mcp('tools/call', { name: 'drop_tables' })).body.error.code, -32602);
  assert.equal((await mcp('prompts/list', {})).body.error.code, -32601);
  const post = (body) => fetch(`${BASE}/mcp.php`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...bearer() }, body });
  assert.equal((await (await post('{not json')).json()).error.code, -32700);
  // Batches were removed from MCP in 2025-06-18.
  const batch = JSON.stringify([{ jsonrpc: '2.0', id: 1, method: 'ping' }]);
  assert.equal((await (await post(batch)).json()).error.code, -32600);
});

test('a read-only token cannot save funnels and is told how to get the permission', { skip: !hasPhp }, async () => {
  for (const [name, args] of [['create_funnel', { site_id: 'private_site', name: 'Blog readers', steps: [{ page: '/blog/first-post' }] }], ['update_funnel', { site_id: 'private_site', id: 1, name: 'Renamed' }]]) {
    const result = await callTool(name, args);
    assert.equal(result.isError, true);
    assert.match(result.text, /Settings → AI assistants/);
    assert.match(result.text, /Can edit funnels/);
  }
  const listed = await callTool('get_funnel', { site_id: 'private_site' });
  assert.deepEqual(listed.data.saved_funnels.map((funnel) => funnel.name), ['Pricing to signup']);
});

test('a token allowed to edit creates and edits funnels, goals and journeys', { skip: !hasPhp }, async () => {
  const write = (name, args) => callTool(name, { site_id: 'private_site', ...args }, bearer(writerToken));

  const funnel = await write('create_funnel', { name: 'Blog to upload', steps: [{ page: '/blog/first-post' }, { event: 'Upload Error' }] });
  assert.deepEqual(funnel.data.saved, { id: 2, name: 'Blog to upload', kind: 'funnel', steps: ['Page /blog/first-post', 'Event Upload Error'] });
  const goal = await write('create_funnel', { kind: 'goal', name: 'Signed up', steps: [{ event: 'Signup' }] });
  assert.equal(goal.data.saved.kind, 'goal');
  const journey = await write('create_funnel', { kind: 'journey', name: 'Pricing journey', steps: [{ page: '/pricing' }, { event: 'Signup' }] });
  assert.equal(journey.data.saved.kind, 'journey');

  // Saved funnels read back like the ones made in the dashboard.
  const saved = await callTool('get_funnel', { site_id: 'private_site', funnel: 'Blog to upload' });
  assert.deepEqual(saved.data.steps.map((step) => step.visits), [1, 1]);
  const journeys = await callTool('get_journey', { site_id: 'private_site' });
  assert.deepEqual(journeys.data.saved_journeys, [{ id: journey.data.saved.id, name: 'Pricing journey', entry: 'Page /pricing', exit: 'Event Signup' }]);
  const dashboard = spawnSync('php', ['-r', `
    require '${workdir.replace(/\\/g, '/')}/vendor/autoload.php';
    $rows = Petitstats\\Database\\Connections::forSite('private_site')->query('SELECT name, kind, steps FROM funnels ORDER BY id');
    $out = []; while ($row = $rows->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; } echo json_encode($out);
  `], { env: phpEnv() });
  const rows = JSON.parse(dashboard.stdout.toString());
  assert.deepEqual(JSON.parse(rows[1].steps), [{ type: 'pageview', value: '/blog/first-post', label: '/blog/first-post' }, { type: 'event', value: 'Upload Error', label: 'Upload Error' }]);

  const renamed = await write('update_funnel', { id: 2, name: 'Blog to errors' });
  assert.deepEqual(renamed.data.saved.steps, ['Page /blog/first-post', 'Event Upload Error']);
  const restepped = await write('update_funnel', { id: 2, steps: [{ page: '/pricing' }, { page: '/blog/first-post' }, { event: 'Upload Error' }] });
  assert.equal(restepped.data.saved.name, 'Blog to errors');
  assert.equal(restepped.data.saved.steps.length, 3);
  assert.equal(restepped.data.saved.kind, 'funnel');

  const fails = async (name, args, pattern) => {
    const result = await write(name, args);
    assert.equal(result.isError, true, `${name} ${JSON.stringify(args)}`);
    assert.match(result.text, pattern);
  };
  await fails('create_funnel', { name: 'pricing TO signup', steps: [{ page: '/' }] }, /already named "Pricing to signup" \(id 1\)/);
  await fails('create_funnel', { kind: 'goal', name: 'Two goals', steps: [{ page: '/' }, { page: '/pricing' }] }, /exactly one step/);
  await fails('create_funnel', { kind: 'journey', name: 'No exit', steps: [{ page: '/' }] }, /exactly two steps/);
  await fails('create_funnel', { name: ' ', steps: [{ page: '/' }] }, /name must not be empty/);
  await fails('create_funnel', { name: 'Bad step', steps: [{ page: '/', event: 'x' }] }, /either a page or an event/);
  await fails('update_funnel', { id: 99, name: 'Ghost' }, /No funnel, goal or journey has the id 99/);
  await fails('update_funnel', { id: 2 }, /new name, new steps, or both/);
  await fails('update_funnel', { id: 2, name: 'Signed up' }, /already named/);
  await fails('update_funnel', { id: goal.data.saved.id, steps: [{ page: '/' }, { page: '/pricing' }] }, /exactly one step/);
  // Renaming to its own name, in another case, is allowed.
  assert.equal((await write('update_funnel', { id: 2, name: 'BLOG TO ERRORS' })).data.saved.name, 'BLOG TO ERRORS');
});
