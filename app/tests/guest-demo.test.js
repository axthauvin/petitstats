const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const hasPhp = spawnSync('php', ['-v']).status === 0;
const PORT = 18000 + Math.floor(Math.random() * 1000);
const BASE = `http://127.0.0.1:${PORT}`;

let workdir;
let server;

/** Points the app at the isolated data directory instead of ../petitstats-data. */
const phpEnv = () => ({ ...process.env, PETITSTATS_DATA_DIR: path.join(workdir, 'data') });

/** Isolated copy of the app with one public and one private website. */
function prepareInstall() {
  workdir = fs.mkdtempSync(path.join(os.tmpdir(), 'petitstats-guest-'));
  fs.cpSync(path.join(root, 'dashboard'), path.join(workdir, 'dashboard'), { recursive: true });
  fs.cpSync(path.join(root, 'src'), path.join(workdir, 'src'), { recursive: true });
  fs.cpSync(path.join(root, 'templates'), path.join(workdir, 'templates'), { recursive: true });
  fs.cpSync(path.join(root, 'vendor'), path.join(workdir, 'vendor'), { recursive: true });
  const data = path.join(workdir, 'data');
  fs.mkdirSync(data);
  const site = (id, extra = {}) => ({
    id, name: id, domain: 'localhost', write_key: `${id}-secret-key`,
    allowed_domains: ['localhost'], internal_ips: [], retention_days: 395, ...extra,
  });
  fs.writeFileSync(path.join(data, 'sites.json'), JSON.stringify([
    site('demo_site', { is_public: true }),
    site('private_site'),
  ]));
  // Auth database (onboarding done) and one pageview per website.
  const setup = spawnSync('php', ['-r', `
    require '${workdir}/vendor/autoload.php';
    Petitstats\\Auth\\AuthDatabase::connection()->exec("INSERT INTO users (email, password, verified, roles_mask, registered) VALUES ('a@b.c', 'x', 1, 1, 0)");
    foreach (['demo_site', 'private_site'] as $id) {
      $db = Petitstats\\Database\\Connections::forSite($id);
      $db->exec("INSERT INTO user_activity (session_id, visitor_id, action) VALUES ('s1', 'v1', '{\\"name\\":\\"pageview\\",\\"data\\":{\\"path\\":\\"/\\"}}')");
      $db->exec("INSERT INTO user_activity (session_id, visitor_id, action) VALUES ('s1', 'v1', '{\\"name\\":\\"_ml_engaged\\",\\"data\\":{\\"path\\":\\"/\\"}}')");
      $db->exec("INSERT INTO user_activity (session_id, visitor_id, action) VALUES ('s1', 'v1', '{\\"name\\":\\"signup\\",\\"data\\":{\\"path\\":\\"/\\"}}')");
    }
  `], { env: phpEnv() });
  assert.equal(setup.status, 0, setup.stdout.toString() + setup.stderr.toString());
}

async function waitForServer() {
  for (let i = 0; i < 50; i++) {
    try { await fetch(`${BASE}/dashboard/login.php`); return; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
  throw new Error('PHP server did not start');
}

const api = (endpoint, init) => fetch(`${BASE}/dashboard/src/api/${endpoint}`, { redirect: 'manual', ...init });

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

const readEndpoints = ['stats.php', 'events.php', 'sessions.php', 'acquisition.php', 'funnels.php', 'revenue.php'];

for (const endpoint of readEndpoints) {
  test(`guests can read ${endpoint} for a public site`, { skip: !hasPhp }, async () => {
    const res = await api(`${endpoint}?site_id=demo_site&range=7d`);
    const body = await res.json();
    assert.equal(res.status, 200, JSON.stringify(body));
    assert.equal(body.error, undefined);
  });

  test(`guests get 401 from ${endpoint} for a private site`, { skip: !hasPhp }, async () => {
    const res = await api(`${endpoint}?site_id=private_site&range=7d`);
    assert.equal(res.status, 401);
  });
}

test('funnel steps never offer internal events', { skip: !hasPhp }, async () => {
  const body = await (await api('funnels.php?site_id=demo_site')).json();
  assert.deepEqual(body.events, ['signup']);
  assert.deepEqual(body.pages, ['/']);
});

test('guests only list public sites, without tracking secrets', { skip: !hasPhp }, async () => {
  const res = await api('sites.php');
  const body = await res.json();
  assert.equal(res.status, 200);
  assert.deepEqual(body.sites.map((s) => s.id), ['demo_site']);
  assert.equal(body.sites[0].write_key, undefined);
  assert.ok(!JSON.stringify(body).includes('secret-key'));
});

test('stats never leak other sites or keys to guests', { skip: !hasPhp }, async () => {
  const body = await (await api('stats.php?site_id=demo_site')).json();
  assert.deepEqual(body.available_sites.map((s) => s.id), ['demo_site']);
  assert.ok(!JSON.stringify(body).includes('secret-key'));
});

test('guests cannot read tracking config or write anything', { skip: !hasPhp }, async () => {
  assert.equal((await api('sites.php?action=tracking-config&id=demo_site')).status, 401);
  assert.equal((await api('sites.php', { method: 'POST', body: '{"id":"x","domain":"x.com"}' })).status, 401);
  assert.equal((await api('sites.php?action=delete&id=demo_site')).status, 401);
  assert.equal((await api('sessions.php?site_id=demo_site&session_id=s1', { method: 'DELETE' })).status, 401);
  assert.equal((await api('funnels.php?site_id=demo_site', { method: 'POST', body: '{"name":"x","steps":[{"type":"pageview","value":"/"}]}' })).status, 401);
  assert.equal((await api('funnels.php?site_id=demo_site&id=1', { method: 'DELETE' })).status, 401);
});

test('administration endpoints stay closed to guests', { skip: !hasPhp }, async () => {
  for (const endpoint of ['users.php', 'database.php', 'import.php', 'privacy.php?site_id=demo_site&visitor_id=v1']) {
    const res = await api(endpoint);
    assert.equal(res.status, 401, endpoint);
  }
});

test('dashboard opens the demo for guests and keeps private sites behind login', { skip: !hasPhp }, async () => {
  const demo = await fetch(`${BASE}/dashboard/?demo=1`, { redirect: 'manual' });
  assert.equal(demo.status, 302);
  assert.match(demo.headers.get('location'), /\/dashboard\/\?site=demo_site#overview$/);

  const page = await fetch(`${BASE}/dashboard/?site=demo_site&embed=1`, { redirect: 'manual' });
  const html = await page.text();
  assert.equal(page.status, 200);
  assert.match(html, /window\.PETITSTATS_GUEST = true/);
  assert.match(html, /window\.PETITSTATS_EMBED = true/);
  assert.match(html, /class="guest-mode embed-mode"/);
  assert.ok(!html.includes('href="#settings" class="footer-link'), 'settings link must be hidden');
  assert.ok(!html.includes('btnSidebarImport'), 'import must be hidden');

  const priv = await fetch(`${BASE}/dashboard/?site=private_site`, { redirect: 'manual' });
  assert.equal(priv.status, 302);
  assert.match(priv.headers.get('location'), /login\.php$/);

  const none = await fetch(`${BASE}/dashboard/`, { redirect: 'manual' });
  assert.match(none.headers.get('location'), /login\.php$/);
});
