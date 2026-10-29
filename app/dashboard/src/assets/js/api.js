/**
 * Petitstats API Client Helper
 */

// Session token the API requires on every state-changing request (see Csrf.php).
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";

async function apiFetch(url, init = {}) {
  const method = (init.method || "GET").toUpperCase();
  if (method !== "GET" && method !== "HEAD") {
    const headers = new Headers(init.headers);
    headers.set("X-CSRF-Token", csrfToken);
    init = { ...init, headers };
  }
  const res = await fetch(url, init);
  if (res.status === 401) showSessionBanner("signed-out");
  else if (res.status === 403 && res.headers.get("X-Petitstats-Error") === "csrf") showSessionBanner("stale");
  return res;
}

/** Tells the user why their action failed: the session ended, or the page holds an outdated token. */
function showSessionBanner(reason) {
  const banner = document.getElementById("sessionBanner");
  if (!banner) return;
  const signedOut = reason === "signed-out";
  const link = banner.querySelector("a");
  banner.querySelector("span").textContent = signedOut
    ? "You have been signed out. Sign in again to keep working."
    : "This page is out of date, so your last change was not saved. Reload the page and try again.";
  link.textContent = signedOut ? "Sign in again" : "Reload page";
  link.href = signedOut ? `/dashboard/login.php?next=${encodeURIComponent(location.pathname + location.search + location.hash)}` : location.href;
  link.onclick = signedOut ? null : (event) => { event.preventDefault(); location.reload(); };
  banner.hidden = false;
}

const Api = {
  base: "/dashboard/src/api",

  async getDatabaseConfig() {
    const res = await apiFetch(`${this.base}/database.php`); const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async databaseConnector(action, config) {
    const res = await apiFetch(`${this.base}/database.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...config }) });
    const data = await res.json(); if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async getStats(range = "7d", siteId = "", customDates = null) {
    const targetSite =
      siteId && siteId !== "all"
        ? siteId
        : window.App &&
            window.App.currentSiteId &&
            window.App.currentSiteId !== "all"
          ? window.App.currentSiteId
          : "";
    const params = new URLSearchParams({ range });
    if (targetSite) params.append("site_id", targetSite);
    const dates = customDates || (window.App && window.App.customDates);
    if (range === "custom" && dates) {
      if (dates.from) params.append("from", dates.from);
      if (dates.to) params.append("to", dates.to);
    }
    window.Filters?.appendTo(params);
    const res = await apiFetch(`${this.base}/stats.php?${params.toString()}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async getAcquisition(range = "7d", siteId = "", customDates = null) {
    const params = new URLSearchParams({ range, site_id: siteId || window.App?.currentSiteId || "" });
    const dates = customDates || window.App?.customDates;
    if (range === "custom" && dates?.from && dates?.to) { params.append("from", dates.from); params.append("to", dates.to); }
    const res = await apiFetch(`${this.base}/acquisition.php?${params}`); const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async getRevenue(range = "7d", siteId = "", customDates = null) {
    const params = new URLSearchParams({ range, site_id: siteId || window.App?.currentSiteId || "" });
    const dates = customDates || window.App?.customDates;
    if (range === "custom" && dates?.from && dates?.to) { params.append("from", dates.from); params.append("to", dates.to); }
    window.Filters?.appendTo(params);
    const res = await apiFetch(`${this.base}/revenue.php?${params}`); const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async getEvents({
    range = "7d",
    page = 1,
    limit = 50,
    search = "",
    eventName = "",
    events = [],
    sessionId = "",
    siteId = "",
    customDates = null,
  } = {}) {
    const targetSite =
      siteId && siteId !== "all"
        ? siteId
        : window.App &&
            window.App.currentSiteId &&
            window.App.currentSiteId !== "all"
          ? window.App.currentSiteId
          : "";
    const params = new URLSearchParams({
      range,
      page: page.toString(),
      limit: limit.toString(),
    });
    if (search) params.append("search", search);
    if (eventName) params.append("event_name", eventName);
    if (events.length) params.append("events", JSON.stringify(events));
    if (sessionId) params.append("session_id", sessionId);
    if (targetSite) params.append("site_id", targetSite);
    const dates = customDates || (window.App && window.App.customDates);
    if (range === "custom" && dates) {
      if (dates.from) params.append("from", dates.from);
      if (dates.to) params.append("to", dates.to);
    }

    const res = await apiFetch(`${this.base}/events.php?${params.toString()}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async getSessions({
    range = "7d",
    page = 1,
    limit = 25,
    search = "",
    siteId = "",
    customDates = null,
    date = "",
    eventName = "",
  } = {}) {
    const targetSite =
      siteId && siteId !== "all"
        ? siteId
        : window.App &&
            window.App.currentSiteId &&
            window.App.currentSiteId !== "all"
          ? window.App.currentSiteId
          : "";
    const params = new URLSearchParams({
      range,
      page: page.toString(),
      limit: limit.toString(),
    });
    if (search) params.append("search", search);
    if (date) params.append("date", date);
    if (eventName && eventName !== "all") params.append("event_name", eventName);
    if (targetSite) params.append("site_id", targetSite);
    const dates = customDates || (window.App && window.App.customDates);
    if (range === "custom" && dates) {
      if (dates.from) params.append("from", dates.from);
      if (dates.to) params.append("to", dates.to);
    }
    window.Filters?.appendTo(params);

    const res = await apiFetch(`${this.base}/sessions.php?${params.toString()}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async getSessionDetails(sessionId, siteId = "") {
    const params = new URLSearchParams({ session_id: sessionId });
    if (siteId) params.append("site_id", siteId);
    const res = await apiFetch(`${this.base}/sessions.php?${params.toString()}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async deleteSession(sessionId, siteId = "") {
    const res = await apiFetch(`${this.base}/sessions.php`, {
      method: "DELETE",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: sessionId, site_id: siteId || window.App?.currentSiteId || "" }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  },

  async getFunnels({ range = "7d", siteId = "", customDates = null } = {}) {
    const targetSite = siteId || window.App?.currentSiteId || "";
    const params = new URLSearchParams({ range });
    if (targetSite) params.append("site_id", targetSite);
    const dates = customDates || window.App?.customDates;
    if (range === "custom" && dates?.from && dates?.to) {
      params.append("from", dates.from); params.append("to", dates.to);
    }
    const res = await apiFetch(`${this.base}/funnels.php?${params}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  },

  async saveFunnel(funnel, siteId = "") {
    const res = await apiFetch(`${this.base}/funnels.php`, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ site_id: siteId || window.App?.currentSiteId, ...funnel }),
    });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  },

  async deleteFunnel(id, siteId = "") {
    const params = new URLSearchParams({ id, site_id: siteId || window.App?.currentSiteId });
    const res = await apiFetch(`${this.base}/funnels.php?${params}`, { method: "DELETE" });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  },

  async getSites() {
    const res = await apiFetch(`${this.base}/sites.php`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async getTrackingConfig(id) {
    // Tracking secrets are admin-only; skip the request entirely in the live demo.
    if (window.PETITSTATS_GUEST) throw new Error("Not available in demo mode.");
    const res = await apiFetch(`${this.base}/sites.php?action=tracking-config&id=${encodeURIComponent(id)}`); const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async updateSiteConfig(config) {
    const res = await apiFetch(`${this.base}/sites.php`, {method:'PATCH', headers:{'Content-Type':'application/json'}, body:JSON.stringify(config)}); const data=await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async createSite({ id, name, domain }) {
    const res = await apiFetch(`${this.base}/sites.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, name, domain }),
    });
    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async deleteSite(id) {
    const res = await apiFetch(
      `${this.base}/sites.php?id=${encodeURIComponent(id)}`,
      {
        method: "DELETE",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      },
    );
    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async getImportProviders() {
    const res = await apiFetch(`${this.base}/import.php?action=providers`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  },

  async inspectImport(formData) {
    const res = await apiFetch(`${this.base}/import.php?action=inspect`, {
      method: "POST",
      body: formData,
    });
    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async submitImport(formData) {
    const res = await apiFetch(`${this.base}/import.php`, {
      method: "POST",
      body: formData,
    });
    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async trackingServerSettings(body = null) {
    const init = body ? { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) } : {};
    const res = await apiFetch(`${this.base}/tracking.php`, init); const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async accessTokens(method = "GET", body = null) {
    const init = body ? { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) } : { method };
    const res = await apiFetch(`${this.base}/tokens.php`, init); const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async getVersion(refresh = false) {
    const res = await apiFetch(`${this.base}/version.php${refresh ? "?refresh=1" : ""}`); const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  /** What changed in the releases newer than `after`, up to and including `upTo`. */
  async getChangelog({ after = null, upTo = null, refresh = false } = {}) {
    const params = new URLSearchParams();
    if (after) params.set("after", after); if (upTo) params.set("up_to", upTo); if (refresh) params.set("refresh", "1");
    const res = await apiFetch(`${this.base}/changelog.php?${params}`); const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  /** Runs one step of an update from the dashboard: check, download, backup, install or rollback. */
  async runUpdateStep(step) {
    const res = await apiFetch(`${this.base}/update.php`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ step }) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`); return data;
  },

  async getUsers() {
    const res = await apiFetch(`${this.base}/users.php`);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async inviteUser(email) {
    const res = await apiFetch(`${this.base}/users.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async updateUserRole(id, role) {
    const res = await apiFetch(`${this.base}/users.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "update_role", id, role }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },

  async deleteUser(id) {
    const res = await apiFetch(`${this.base}/users.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "delete", id }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  },
};

window.Api = Api;
