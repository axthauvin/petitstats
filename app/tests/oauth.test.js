const assert = require('node:assert/strict');
const { spawn, spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const hasPhp = spawnSync('php', ['-v']).status === 0;
const PORT = 20000 + Math.floor(Math.random() * 1000);
const BASE = `http://127.0.0.1:${PORT}`;
const MCP_URL = `${BASE}/mcp.php`;
const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
// Test-only account created in the isolated install below.
const EMAIL = 'owner@petitstats.test';
const PASSWORD = 'Test-Passw0rd!';

let workdir;
let server;
let cookie = '';

const phpEnv = () => ({ ...process.env, PETITSTATS_DATA_DIR: path.join(workdir, 'data') });

function prepareInstall() {
  workdir = fs.mkdtempSync(path.join(os.tmpdir(), 'petitstats-oauth-'));
  for (const entry of ['dashboard', 'src', 'templates', 'vendor', 'oauth', '.well-known', 'mcp.php']) {
    fs.cpSync(path.join(root, entry), path.join(workdir, entry), { recursive: true });
  }
  const data = path.join(workdir, 'data');
  fs.mkdirSync(data);
  fs.writeFileSync(path.join(data, 'sites.json'), JSON.stringify([{
    id: 'private_site', name: 'Private', domain: 'localhost', write_key: 'k',
    allowed_domains: ['localhost'], internal_ips: [], retention_days: 395,
  }]));
  const setup = spawnSync('php', ['-r', `
    require '${workdir.replace(/\\/g, '/')}/vendor/autoload.php';
    Petitstats\\Auth\\Auth::createUser('${EMAIL}', '${PASSWORD}', 'admin');
    Petitstats\\Database\\Connections::forSite('private_site')->exec("INSERT INTO user_activity (session_id, visitor_id, action) VALUES ('s1', 'v1', '{\\"name\\":\\"pageview\\",\\"data\\":{\\"path\\":\\"/\\"}}')");
  `], { env: phpEnv() });
  assert.equal(setup.status, 0, setup.stdout.toString() + setup.stderr.toString());
}

/** fetch() keeping the dashboard session cookie, without following redirects. */
async function browser(url, init = {}) {
  const res = await fetch(url.startsWith('http') ? url : BASE + url, { redirect: 'manual', ...init, headers: { ...(init.headers || {}), Cookie: cookie } });
  for (const value of res.headers.getSetCookie()) {
    if (value.startsWith('petitstats_session=')) cookie = value.split(';')[0];
  }
  return res;
}
/** The CSRF token a page hands to its forms (hidden field) or its API client (meta tag). */
async function csrfToken(path) {
  const html = await (await browser(path)).text();
  return html.match(/name="csrf(?:-token)?" (?:value|content)="([^"]+)"/)[1];
}
const form = (data) => ({ method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data).toString() });
const json = (data) => ({ method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
const pkce = () => {
  const verifier = crypto.randomBytes(32).toString('base64url');
  return { verifier, challenge: crypto.createHash('sha256').update(verifier).digest('base64url') };
};
async function mcp(token, method = 'tools/list', params = {}) {
  const res = await fetch(MCP_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }) });
  return { status: res.status, body: res.status === 200 ? await res.json() : null };
}

/** Registers a client and walks the consent screen; returns the authorization redirect. */
async function authorize({ approve = true, challenge, state = 'xyz', clientId } = {}) {
  const query = new URLSearchParams({
    response_type: 'code', client_id: clientId, redirect_uri: REDIRECT_URI, state,
    code_challenge: challenge, code_challenge_method: 'S256', resource: MCP_URL, scope: 'read',
  });
  const page = await browser(`/oauth/authorize.php?${query}`);
  assert.equal(page.status, 200);
  const html = await page.text();
  const fields = Object.fromEntries([...html.matchAll(/<input type="hidden" name="([^"]+)" value="([^"]*)">/g)].map((m) => [m[1], m[2].replace(/&amp;/g, '&')]));
  const res = await browser('/oauth/authorize.php', form({ ...fields, decision: approve ? 'allow' : 'deny' }));
  assert.equal(res.status, 302);
  return new URL(res.headers.get('location'));
}

async function register() {
  const res = await fetch(`${BASE}/oauth/register.php`, json({ client_name: 'Claude', redirect_uris: [REDIRECT_URI], token_endpoint_auth_method: 'none' }));
  assert.equal(res.status, 201);
  return (await res.json()).client_id;
}

async function connect() {
  const clientId = await register();
  const { verifier, challenge } = pkce();
  const redirect = await authorize({ clientId, challenge });
  const res = await fetch(`${BASE}/oauth/token.php`, form({ grant_type: 'authorization_code', code: redirect.searchParams.get('code'), redirect_uri: REDIRECT_URI, client_id: clientId, code_verifier: verifier, resource: MCP_URL }));
  assert.equal(res.status, 200);
  return { clientId, tokens: await res.json() };
}

test.before(async () => {
  if (!hasPhp) return;
  prepareInstall();
  server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', workdir], { stdio: 'ignore', env: phpEnv() });
  for (let i = 0; i < 50; i++) {
    try { await fetch(`${BASE}/dashboard/login.php`); break; } catch { await new Promise((r) => setTimeout(r, 100)); }
  }
});

test.after(() => {
  server?.kill();
  if (workdir) fs.rmSync(workdir, { recursive: true, force: true });
});

test('an unauthenticated MCP request points to the OAuth metadata', { skip: !hasPhp }, async () => {
  const res = await fetch(MCP_URL, json({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} }));
  assert.equal(res.status, 401);
  const challenge = res.headers.get('www-authenticate');
  const metadataUrl = challenge.match(/resource_metadata="([^"]+)"/)[1];
  assert.equal(metadataUrl, `${BASE}/.well-known/oauth-protected-resource/mcp.php`);

  const resource = await (await fetch(metadataUrl)).json();
  assert.equal(resource.resource, MCP_URL);
  const issuer = resource.authorization_servers[0];

  // RFC 8414 path insertion: /.well-known/oauth-authorization-server + issuer path.
  const server = await (await fetch(`${BASE}/.well-known/oauth-authorization-server${new URL(issuer).pathname}`)).json();
  assert.equal(server.issuer, issuer);
  assert.deepEqual(server.code_challenge_methods_supported, ['S256']);
  assert.ok(server.token_endpoint_auth_methods_supported.includes('none'));
  for (const endpoint of ['authorization_endpoint', 'token_endpoint', 'registration_endpoint']) {
    assert.ok(server[endpoint].startsWith(BASE), endpoint);
  }
});

test('the consent screen requires signing in, then returns to the request', { skip: !hasPhp }, async () => {
  const clientId = await register();
  const query = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: REDIRECT_URI, code_challenge: pkce().challenge, code_challenge_method: 'S256' });
  const res = await browser(`/oauth/authorize.php?${query}`);
  assert.equal(res.status, 302);
  const login = new URL(res.headers.get('location'), BASE);
  assert.equal(login.pathname, '/dashboard/login.php');
  const next = login.searchParams.get('next');
  assert.ok(next.startsWith('/oauth/authorize.php?'));

  const loginUrl = `/dashboard/login.php?next=${encodeURIComponent(next)}`;
  const signedIn = await browser(loginUrl, form({ email: EMAIL, password: PASSWORD, csrf: await csrfToken(loginUrl) }));
  assert.equal(signedIn.status, 302);
  assert.equal(signedIn.headers.get('location'), next);
});

test('login only redirects to local paths', { skip: !hasPhp }, async () => {
  for (const next of ['https://evil.example', '//evil.example', '/\\evil.example']) {
    const res = await browser(`/dashboard/login.php?next=${encodeURIComponent(next)}`);
    assert.equal(res.headers.get('location'), '/dashboard/', next);
  }
});

test('an approved assistant reads the analytics with its token', { skip: !hasPhp }, async () => {
  const { tokens } = await connect();
  assert.equal(tokens.token_type, 'Bearer');
  assert.ok(tokens.refresh_token);
  const { status, body } = await mcp(tokens.access_token, 'tools/call', { name: 'get_overview', arguments: { site_id: 'private_site' } });
  assert.equal(status, 200);
  assert.equal(JSON.parse(body.result.content[0].text).summary.visitors.value, 1);
});

test('the authorization response carries state and iss', { skip: !hasPhp }, async () => {
  const clientId = await register();
  const redirect = await authorize({ clientId, challenge: pkce().challenge, state: 'abc' });
  assert.equal(redirect.origin + redirect.pathname, REDIRECT_URI);
  assert.equal(redirect.searchParams.get('state'), 'abc');
  assert.equal(redirect.searchParams.get('iss'), MCP_URL);
  assert.ok(redirect.searchParams.get('code'));
});

test('denying access sends access_denied back to the assistant', { skip: !hasPhp }, async () => {
  const redirect = await authorize({ clientId: await register(), challenge: pkce().challenge, approve: false });
  assert.equal(redirect.searchParams.get('error'), 'access_denied');
});

test('codes need the PKCE verifier and work only once', { skip: !hasPhp }, async () => {
  const clientId = await register();
  const { verifier, challenge } = pkce();
  const code = (await authorize({ clientId, challenge })).searchParams.get('code');
  const exchange = (codeVerifier) => fetch(`${BASE}/oauth/token.php`, form({ grant_type: 'authorization_code', code, redirect_uri: REDIRECT_URI, client_id: clientId, code_verifier: codeVerifier }));

  const wrong = await exchange(pkce().verifier);
  assert.equal(wrong.status, 400);
  assert.equal((await wrong.json()).error, 'invalid_grant');
  // A failed attempt burns the code too.
  assert.equal((await (await exchange(verifier)).json()).error, 'invalid_grant');
});

test('refresh tokens rotate', { skip: !hasPhp }, async () => {
  const { clientId, tokens } = await connect();
  const refresh = (token) => fetch(`${BASE}/oauth/token.php`, form({ grant_type: 'refresh_token', refresh_token: token, client_id: clientId }));
  const renewed = await (await refresh(tokens.refresh_token)).json();
  assert.ok(renewed.access_token && renewed.access_token !== tokens.access_token);
  assert.equal((await mcp(renewed.access_token)).status, 200);
  assert.equal((await mcp(tokens.access_token)).status, 401);
  assert.equal((await (await refresh(tokens.refresh_token)).json()).error, 'invalid_grant');
});

test('unregistered redirect URIs are never redirected to', { skip: !hasPhp }, async () => {
  const query = new URLSearchParams({ response_type: 'code', client_id: await register(), redirect_uri: 'https://evil.example/cb', code_challenge: pkce().challenge, code_challenge_method: 'S256' });
  const res = await browser(`/oauth/authorize.php?${query}`);
  assert.equal(res.status, 200);
  assert.match(await res.text(), /Connection failed/);
});

test('loopback redirects match on any port and loopback host', { skip: !hasPhp }, async () => {
  const res = await fetch(`${BASE}/oauth/register.php`, json({ client_name: 'Claude Code', redirect_uris: ['http://localhost/callback'] }));
  const clientId = (await res.json()).client_id;
  const consent = async (redirectUri) => {
    const query = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: redirectUri, code_challenge: pkce().challenge, code_challenge_method: 'S256' });
    return (await browser(`/oauth/authorize.php?${query}`)).text();
  };
  for (const uri of ['http://localhost:51234/callback', 'http://127.0.0.1:51234/callback', 'http://[::1]:51234/callback']) {
    assert.match(await consent(uri), /application running on this computer/, uri);
  }
  assert.match(await consent('http://127.0.0.1:51234/other'), /Connection failed/);
});

test('the consent screen warns when the code goes to a desktop application', { skip: !hasPhp }, async () => {
  const redirectUri = 'cursor://anysphere.cursor-mcp/oauth/callback';
  const res = await fetch(`${BASE}/oauth/register.php`, json({ client_name: 'Cursor', redirect_uris: [redirectUri] }));
  const query = new URLSearchParams({ response_type: 'code', client_id: (await res.json()).client_id, redirect_uri: redirectUri, code_challenge: pkce().challenge, code_challenge_method: 'S256' });
  assert.match(await (await browser(`/oauth/authorize.php?${query}`)).text(), /application running on this computer/);
});

test('registration rejects unsafe redirect URIs', { skip: !hasPhp }, async () => {
  for (const uri of ['http://evil.example/cb', 'javascript:alert(1)', 'https://ok.example/cb#frag']) {
    const res = await fetch(`${BASE}/oauth/register.php`, json({ redirect_uris: [uri] }));
    assert.equal(res.status, 400, uri);
    assert.equal((await res.json()).error, 'invalid_redirect_uri');
  }
});

test('OAuth tokens only open the MCP endpoint', { skip: !hasPhp }, async () => {
  const { tokens } = await connect();
  const res = await fetch(`${BASE}/dashboard/src/api/stats.php?site_id=private_site`, { headers: { Authorization: `Bearer ${tokens.access_token}` } });
  assert.equal(res.status, 401);
});

test('disconnecting an assistant revokes its tokens', { skip: !hasPhp }, async () => {
  const { clientId, tokens } = await connect();
  const list = await (await browser('/dashboard/src/api/tokens.php')).json();
  assert.equal(list.mcp_url, MCP_URL);
  assert.ok(list.apps.some((app) => app.client_id === clientId && app.name === 'Claude'));

  const csrf = await csrfToken('/dashboard/');
  const res = await browser('/dashboard/src/api/tokens.php', { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ app: clientId }) });
  assert.equal(res.status, 200);
  assert.equal((await mcp(tokens.access_token)).status, 401);
});

test('an assistant edits funnels only once allowed in the settings, even after signing in again', { skip: !hasPhp }, async () => {
  const { clientId, tokens } = await connect();
  const create = async (token, name) => {
    const { body } = await mcp(token, 'tools/call', { name: 'create_funnel', arguments: { site_id: 'private_site', name, steps: [{ page: '/' }] } });
    return body.result;
  };
  const allow = async (canWrite, app = clientId) => browser('/dashboard/src/api/tokens.php', { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken('/dashboard/') }, body: JSON.stringify({ app, can_write: canWrite }) });
  const listed = async () => (await (await browser('/dashboard/src/api/tokens.php')).json()).apps.find((app) => app.client_id === clientId);

  const refused = await create(tokens.access_token, 'Home');
  assert.equal(refused.isError, true);
  assert.match(refused.content[0].text, /Can edit funnels/);
  assert.equal((await listed()).can_write, false);

  assert.equal((await allow(true)).status, 200);
  assert.equal((await listed()).can_write, true);
  assert.equal((await create(tokens.access_token, 'Home')).isError, undefined);

  // A new sign-in of the same application keeps the permission.
  const { verifier, challenge } = pkce();
  const redirect = await authorize({ clientId, challenge });
  const again = await (await fetch(`${BASE}/oauth/token.php`, form({ grant_type: 'authorization_code', code: redirect.searchParams.get('code'), redirect_uri: REDIRECT_URI, client_id: clientId, code_verifier: verifier, resource: MCP_URL }))).json();
  assert.equal((await create(again.access_token, 'Home again')).isError, undefined);

  assert.equal((await allow(false)).status, 200);
  assert.equal((await create(again.access_token, 'Refused')).isError, true);
  assert.equal((await create(tokens.access_token, 'Refused')).isError, true);
  assert.equal((await allow(true, 'mlc_unknown')).status, 404);
});

test('signing in requires the form CSRF token', { skip: !hasPhp }, async () => {
  const res = await fetch(`${BASE}/dashboard/login.php`, { redirect: 'manual', ...form({ email: EMAIL, password: PASSWORD }) });
  assert.equal(res.status, 200);
  assert.match(await res.text(), /This page expired/);
});

test('dashboard API writes require the session CSRF token', { skip: !hasPhp }, async () => {
  const create = (headers) => browser('/dashboard/src/api/tokens.php', { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify({ name: 'csrf' }) });
  const missing = await create({});
  assert.equal(missing.status, 403);
  assert.equal(missing.headers.get('x-petitstats-error'), 'csrf');
  assert.equal((await create({ 'X-CSRF-Token': 'forged' })).status, 403);
  assert.equal((await create({ 'X-CSRF-Token': await csrfToken('/dashboard/') })).status, 200);
});

test('OAuth endpoints answer CORS preflights', { skip: !hasPhp }, async () => {
  for (const endpoint of ['/mcp.php', '/oauth/token.php', '/oauth/register.php', '/.well-known/oauth-protected-resource/mcp.php']) {
    const res = await fetch(BASE + endpoint, { method: 'OPTIONS', headers: { Origin: 'https://inspector.example', 'Access-Control-Request-Method': 'POST' } });
    assert.equal(res.status, 204, endpoint);
    assert.equal(res.headers.get('access-control-allow-origin'), '*');
  }
});
