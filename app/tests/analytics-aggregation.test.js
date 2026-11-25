const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('audience breakdowns aggregate distinct visitors and use visitor labels', () => {
  const stats = read('src/Analytics/Reports/EnvironmentReport.php') + read('src/Analytics/Reports/CountriesReport.php');
  const overview = read('templates/dashboard/pages/overview.php');

  assert.match(stats, /COUNT\(DISTINCT COALESCE\(visitor_id, session_id\)\) as count/);
  assert.match(stats, /\$totalVisitors > 0 \? round\(\(\$count \/ \$totalVisitors\)/);
  assert.match(stats, /GROUP BY country, country_code/);
  assert.match(overview, /id="envColumnHeader">Browser<\/span>\s*<span style="text-align: right;">Visitors<\/span>/);
  assert.match(overview, /<span>Country<\/span>\s*<span style="text-align: right;">Visitors<\/span>/);
});

test('visitor-facing secondary analytics use visitor ids instead of sessions', () => {
  const sites = read('src/Analytics/SiteOverview.php');
  const funnels = read('dashboard/src/api/funnels.php');

  assert.doesNotMatch(sites, /COUNT\(DISTINCT session_id\) FROM user_activity/);
  assert.match(sites, /COUNT\(DISTINCT COALESCE\(visitor_id, session_id\)\)/);
  assert.match(funnels, /COUNT\(DISTINCT COALESCE\(visitor_id, session_id\)\)/);
});

test('overview bounce-rate query does not nest aggregate functions', () => {
  const stats = read('src/Analytics/Reports/SummaryReport.php');

  assert.doesNotMatch(stats, /MAX\(1, COUNT\(\*\)\)/);
  assert.match(stats, /NULLIF\(COUNT\(\*\), 0\) as bounce_rate/);
});

test('overview treats MySQL JSON null referrers as direct traffic', () => {
  const stats = read('src/Analytics/Reports/ReferrersReport.php');
  const db = read('src/Database/DatabaseConnection.php');

  assert.match(stats, /strtolower\(\$rawRef\) !== 'null'/);
  assert.match(db, /NULLIF\(NULLIF\(REPLACE\(SUBSTRING_INDEX/);
});

test('local GeoIP database is refreshed without an API key or visitor lookup', () => {
  const geo = read('src/Geo/GeoLocation.php');

  assert.match(geo, /'GeoLite2-City'/);
  assert.match(geo, /'GeoLite2-ASN'/);
  assert.match(geo, /\$edition \. '\.tar\.gz'/);
  assert.match(geo, /MAX_AGE_SECONDS = 7 \* 86400/);
  assert.match(geo, /new \\MaxMind\\Db\\Reader/);
  assert.match(geo, /CURLOPT_FILE => \$file/);
  assert.match(geo, /gzread\(\$input, min\(\$size, 1024 \* 1024\)\)/);
  assert.doesNotMatch(geo, /api[_-]?key|token=/i);
});

test('sessions API deletes a selected visitor and UI requires confirmation', () => {
  const api = read('dashboard/src/api/sessions.php');
  const client = read('dashboard/src/assets/js/api.js');
  const sessions = read('dashboard/src/assets/js/sessions.js');

  assert.match(api, /Auth::requireAdmin\(\)/);
  assert.match(api, /DELETE FROM user_activity WHERE visitor_id = :visitor OR session_id = :session_id/);
  assert.match(api, /DELETE FROM user_activity WHERE session_id = :session_id/);
  assert.match(client, /async deleteSession\(sessionId, siteId/);
  assert.match(sessions, /Delete this visitor and all of their visits/);
});

test('session previews separate page views from custom events', () => {
  const api = read('dashboard/src/api/sessions.php');
  const sessions = read('dashboard/src/assets/js/sessions.js');

  assert.match(api, /json_extract\(action, '\$\.name'\) = 'pageview' THEN 1 ELSE 0 END\) as pageview_count/);
  assert.match(api, /NOT IN \('pageview', '_ml_engaged'\) THEN 1 ELSE 0 END\) as event_count/);
  assert.match(sessions, /pageviewCount.*page view/);
  assert.match(sessions, /eventCount.*event/);
});

test('session details expose page views and custom events separately', () => {
  const api = read('dashboard/src/api/sessions.php');
  const page = read('templates/dashboard/pages/sessions.php');

  assert.match(api, /'pageview_count' => array_sum\(array_column\(\$visits, 'pageview_count'\)\)/);
  assert.match(api, /'custom_event_count' => array_sum\(array_column\(\$visits, 'event_count'\)\)/);
  assert.match(page, /id="detailPageviews"/);
  assert.match(page, /id="detailLastActivity"/);
});
