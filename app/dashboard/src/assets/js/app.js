/**
 * Petitstats Main Application Controller
 * Enforces per-site navigation via GET URL parameter (?site=<site_id>),
 * ensuring analytics are ALWAYS scoped to an active individual website (never 'all').
 */

const App = {
  currentPage: "websites",
  currentRange: "7d",
  customDates: null,
  currentSiteId: null,
  refreshInterval: null,
  // Live demo: anonymous, read-only access pinned to one public website.
  isGuest: window.PETITSTATS_GUEST === true,
  guestSiteId: window.PETITSTATS_PUBLIC_SITE || null,

  /**
   * Warns administrators when the web server ignores the .htaccess rules
   * (Nginx, Caddy…): composer.json is then served as JSON instead of denied.
   */
  checkServerConfig() {
    const banner = document.getElementById("serverConfigWarning");
    // Local development servers (php -S) serve every file on purpose.
    if (!banner || /^(localhost|127\.0\.0\.1|\[::1\])$|\.(localhost|test)$/.test(window.location.hostname)) return;
    fetch("/composer.json", { cache: "no-store" })
      .then((res) => {
        // Some servers answer missing files with an HTML page and a 200 status.
        banner.hidden = !(res.ok && (res.headers.get("Content-Type") || "").includes("json"));
      })
      .catch(() => {});
  },

  /**
   * Marks the Settings link and the Updates tab when a newer Petitstats release
   * is available (administrators only), and says what changed after an update.
   */
  checkForUpdate() {
    if (this.isGuest) return;
    Api.getVersion().then((data) => {
      if (window.PETITSTATS_ADMIN === true) this.showUpdateDots(data.update_available);
      Changelog.showWhatsNewOnce(data.installed);
    }).catch(() => {});
  },

  showUpdateDots(visible) {
    document.querySelectorAll("[data-update-dot]").forEach((dot) => { dot.hidden = !visible; });
  },

  init() {
    // Read URL search parameter (?site=... or ?site_id=...)
    const urlParams = new URLSearchParams(window.location.search);
    const siteParam = urlParams.get("site") || urlParams.get("site_id");
    if (siteParam && siteParam !== "all") {
      this.currentSiteId = siteParam;
    }
    if (this.isGuest) this.currentSiteId = this.guestSiteId;

    this.checkServerConfig();
    this.checkForUpdate();
    this.bindNavigation();
    this.bindHeaderActions();
    this.restoreDateRangePreference();
    this.syncDateRangeControl();
    this.bindModals();
    ClipboardHelper.init();

    // Initialize sub-controllers
    WebsitesPage.init();
    OverviewPage.init();
    EventsPage.init();
    SessionsPage.init();
    FunnelsPage.init();
    RevenuePage.init();

    // Handle URL routing
    window.addEventListener("hashchange", () => this.handleRoute());
    window.addEventListener("popstate", () => this.handleRoute());
    this.handleRoute();

    // Auto-refresh every 30 seconds for live visitors
    this.refreshInterval = setInterval(() => {
      // Settings contains editable forms. Reloading it here would replace values
      // the user may still be entering, notably the database connector fields.
      if (this.currentPage !== "settings") {
        this.refreshCurrentPage(true);
      }
    }, 30000);
  },

  bindNavigation() {
    // Back to All Websites button
    const backBtn = document.getElementById("btnBackToWebsites");
    if (backBtn) {
      backBtn.addEventListener("click", (e) => {
        e.preventDefault();
        sessionStorage.removeItem("petitstats_current_site");
        this.currentSiteId = null;
        const url = new URL(window.location);
        url.searchParams.delete("site");
        url.searchParams.delete("site_id");
        url.hash = "#websites";
        window.history.pushState({}, "", url);
        this.navigateTo("websites");
      });
    }

    // Sidebar navigation links (preserve active site parameter!)
    document.querySelectorAll(".nav-item[data-page]").forEach((item) => {
      item.addEventListener("click", (e) => {
        const page = item.dataset.page;
        if (!page) return;
        e.preventDefault();
        const url = new URL(window.location);
        if (page !== "sessions") url.searchParams.delete("session_id");
        if (page === "settings") {
          this.currentSiteId = null;
          sessionStorage.removeItem("petitstats_current_site");
          url.searchParams.delete("site");
          url.searchParams.delete("site_id");
        } else if (this.currentSiteId) {
          url.searchParams.set("site", this.currentSiteId);
        }
        url.hash = `#${page}`;
        window.history.pushState({}, "", url);
        this.navigateTo(page);
      });
    });

    this.bindSidebarCollapse();
  },

  bindSidebarCollapse() {
    const root = document.documentElement;
    const sidebar = document.getElementById("sidebar");
    const collapseBtn = document.getElementById("btnSidebarCollapse");
    const headerToggleBtn = document.getElementById("btnHeaderSidebarToggle");
    const backdrop = document.getElementById("mobileNavBackdrop");
    const main = document.querySelector(".main-wrapper");
    if (!sidebar || document.body.classList.contains("embed-mode")) return;

    // Phones get a drawer, tablets an icon rail that opens over the page, and
    // desktops a sidebar the user may shrink to the rail (remembered).
    // index.php applies the same rule before the first paint.
    const phone = window.matchMedia("(max-width: 767px)");
    const tablet = window.matchMedia("(min-width: 768px) and (max-width: 1099px)");
    const isOverlay = () => phone.matches || tablet.matches;
    const isHidden = () => document.querySelector(".app-container")?.classList.contains("is-portal");

    let collapsedByUser = false;
    try {
      collapsedByUser = localStorage.getItem("petitstats_sidebar_collapsed") === "true";
    } catch (e) {}
    let isOpen = false;
    let opener = null;

    const render = () => {
      const compact = !isOpen && (tablet.matches || (!isOverlay() && collapsedByUser));
      root.classList.toggle("nav-compact", compact);
      root.classList.toggle("nav-open", isOpen);
      // While the overlay is open, only the navigation can be reached.
      if (main) main.inert = isOpen;
      if (headerToggleBtn) headerToggleBtn.setAttribute("aria-expanded", String(isOpen));
      this.updateSidebarCollapseLabels(isOverlay() ? (isOpen ? "close" : "open") : compact ? "expand" : "collapse");
      // The rail hides the labels: show them as tooltips instead.
      sidebar.querySelectorAll(".nav-title").forEach((title) => {
        const link = title.closest("a, button");
        if (compact && !link.hasAttribute("title")) {
          link.title = title.textContent;
          link.dataset.railTooltip = "";
        } else if (!compact && "railTooltip" in link.dataset) {
          link.removeAttribute("title");
          delete link.dataset.railTooltip;
        }
      });
    };

    const setOpen = (open, { restoreFocus = true } = {}) => {
      if (open === isOpen || (open && isHidden())) return;
      isOpen = open;
      if (open) opener = document.activeElement;
      render();
      if (open && collapseBtn) collapseBtn.focus();
      else if (!open && restoreFocus && opener && opener.isConnected) opener.focus();
    };

    const toggleSidebar = () => {
      if (isOverlay()) {
        setOpen(!isOpen);
        return;
      }
      collapsedByUser = !collapsedByUser;
      try {
        localStorage.setItem("petitstats_sidebar_collapsed", collapsedByUser ? "true" : "false");
      } catch (e) {}
      render();

      // Trigger redraw/resize for charts and dynamic containers
      setTimeout(() => {
        window.dispatchEvent(new Event("resize"));
        if (this.currentPage === "overview" && typeof OverviewPage !== "undefined" && OverviewPage.chart) {
          OverviewPage.chart.resize();
        }
      }, 240);
    };

    render();

    if (collapseBtn) collapseBtn.addEventListener("click", toggleSidebar);
    if (headerToggleBtn) headerToggleBtn.addEventListener("click", () => setOpen(!isOpen));
    if (backdrop) backdrop.addEventListener("click", () => setOpen(false));
    // Following a link closes the overlay; focus then stays with the new page.
    sidebar.querySelectorAll("a, .nav-item button").forEach((link) => {
      link.addEventListener("click", () => setOpen(false, { restoreFocus: false }));
    });
    window.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && isOpen) setOpen(false);
    });
    [phone, tablet].forEach((query) =>
      query.addEventListener("change", () => {
        isOpen = false;
        render();
      }),
    );

    // Keyboard shortcut: Ctrl+B or Cmd+B
    window.addEventListener("keydown", (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "b") {
        const activeTag = document.activeElement ? document.activeElement.tagName : "";
        if (activeTag !== "INPUT" && activeTag !== "TEXTAREA" && activeTag !== "SELECT") {
          e.preventDefault();
          toggleSidebar();
        }
      }
    });
  },

  updateSidebarCollapseLabels(action) {
    const collapseBtn = document.getElementById("btnSidebarCollapse");
    if (!collapseBtn) return;
    const label = {
      collapse: "Collapse navigation",
      expand: "Expand navigation",
      open: "Open navigation",
      close: "Close navigation",
    }[action];
    collapseBtn.setAttribute("title", `${label} (Ctrl+B)`);
    collapseBtn.setAttribute("aria-label", label);
    collapseBtn.setAttribute("aria-expanded", String(action === "collapse" || action === "close"));
  },

  bindHeaderActions() {
    // Website selector dropdown (if present in header)
    const siteSelect = document.getElementById("siteSelect");
    if (siteSelect) {
      siteSelect.addEventListener("change", (e) => {
        const newSiteId = e.target.value;
        if (!newSiteId || newSiteId === "all") return;
        this.currentSiteId = newSiteId;
        const url = new URL(window.location);
        url.searchParams.set("site", newSiteId);
        window.history.replaceState({}, "", url);
        this.updateSidebarSiteInfo(newSiteId);
        this.refreshCurrentPage();
      });
    }

    // Date range dropdown & custom popover
    const rangeSelect = document.getElementById("rangeSelect");
    const customPopover = document.getElementById("customRangePopover");
    const customStart = document.getElementById("customRangeStart");
    const customEnd = document.getElementById("customRangeEnd");
    const applyCustomBtn = document.getElementById("btnApplyCustomRange");
    const cancelCustomBtn = document.getElementById("btnCancelCustomRange");
    const closeCustomBtn = document.getElementById("btnCloseCustomRange");

    this.customRangeStartPicker = new PetitstatsCalendarPicker(customStart);
    this.customRangeEndPicker = new PetitstatsCalendarPicker(customEnd);

    if (rangeSelect) {
      rangeSelect.addEventListener("change", (e) => {
        const val = e.target.value;
        if (val === "custom") {
          if (customPopover) {
            if (!this.customRangeStartPicker.getValue()) {
              const now = new Date();
              const prior = new Date();
              prior.setDate(prior.getDate() - 30);
              this.customRangeStartPicker.setValue(prior.toISOString().split("T")[0]);
              this.customRangeEndPicker.setValue(now.toISOString().split("T")[0]);
            }
            customPopover.style.display = "flex";
          }
          return;
        }

        if (customPopover) customPopover.style.display = "none";
        this.currentRange = val;
        this.customDates = null;
        this.persistDateRangePreference();
        this.refreshCurrentPage();
      });
    }

    if (applyCustomBtn && customStart && customEnd) {
      applyCustomBtn.addEventListener("click", () => {
        let fromVal = this.customRangeStartPicker.getValue();
        let toVal = this.customRangeEndPicker.getValue();
        if (!fromVal || !toVal) {
          alert("Please select both start and end dates.");
          return;
        }
        if (fromVal > toVal) {
          [fromVal, toVal] = [toVal, fromVal];
          this.customRangeStartPicker.setValue(fromVal);
          this.customRangeEndPicker.setValue(toVal);
        }

        this.currentRange = "custom";
        this.customDates = { from: fromVal, to: toVal };

        const customOpt = rangeSelect?.querySelector('option[value="custom"]');
        if (customOpt) {
          customOpt.textContent = `${PetitstatsCalendarPicker.formatIsoDate(fromVal)} — ${PetitstatsCalendarPicker.formatIsoDate(toVal)}`;
        }
        if (rangeSelect) rangeSelect.value = "custom";
        if (customPopover) customPopover.style.display = "none";

        this.persistDateRangePreference();
        this.refreshCurrentPage();
      });
    }

    const closePopover = () => {
      if (customPopover) customPopover.style.display = "none";
      if (this.currentRange !== "custom" && rangeSelect) {
        rangeSelect.value = this.currentRange;
      }
    };

    if (cancelCustomBtn) cancelCustomBtn.addEventListener("click", closePopover);
    if (closeCustomBtn) closeCustomBtn.addEventListener("click", closePopover);

    document.addEventListener("click", (e) => {
      if (
        customPopover &&
        customPopover.style.display === "flex" &&
        !customPopover.contains(e.target) &&
        !rangeSelect?.contains(e.target) &&
        // Calendar popovers are portalled to <body>, outside this container.
        !e.target.closest(".calendar-popover")
      ) {
        closePopover();
      }
    });

    // Manual refresh button
    const refreshBtn = document.getElementById("btnRefresh");
    if (refreshBtn) {
      refreshBtn.addEventListener("click", () => {
        refreshBtn.style.transform = "rotate(360deg)";
        refreshBtn.style.transition = "transform 0.5s ease";
        this.refreshCurrentPage();
        setTimeout(() => {
          refreshBtn.style.transform = "";
          refreshBtn.style.transition = "";
        }, 500);
      });
    }
  },

  dateRangeStorageKey() {
    return "petitstats_date_range";
  },

  /** Restore the user's last selected reporting period after a page reload. */
  restoreDateRangePreference() {
    const validRanges = new Set(["today", "24h", "7d", "30d", "90d", "6m", "all", "custom"]);
    try {
      const saved = JSON.parse(localStorage.getItem(this.dateRangeStorageKey()) || "null");
      if (!saved || !validRanges.has(saved.range)) return;

      if (saved.range === "custom") {
        const { from, to } = saved.customDates || {};
        if (!/^\d{4}-\d{2}-\d{2}$/.test(from || "") || !/^\d{4}-\d{2}-\d{2}$/.test(to || "")) return;
        this.currentRange = "custom";
        this.customDates = from <= to ? { from, to } : { from: to, to: from };
      } else {
        this.currentRange = saved.range;
        this.customDates = null;
      }
    } catch (e) {
      // Storage is optional; retain the default range when it is unavailable.
    }
  },

  persistDateRangePreference() {
    try {
      localStorage.setItem(this.dateRangeStorageKey(), JSON.stringify({
        range: this.currentRange,
        customDates: this.currentRange === "custom" ? this.customDates : null,
      }));
    } catch (e) {
      // Analytics remains usable in browsers where storage is disabled.
    }
  },

  syncDateRangeControl() {
    const rangeSelect = document.getElementById("rangeSelect");
    if (!rangeSelect) return;

    if (this.currentRange === "custom" && this.customDates) {
      const { from, to } = this.customDates;
      this.customRangeStartPicker?.setValue(from);
      this.customRangeEndPicker?.setValue(to);
      const customOption = rangeSelect.querySelector('option[value="custom"]');
      if (customOption) {
        customOption.textContent = `${PetitstatsCalendarPicker.formatIsoDate(from)} — ${PetitstatsCalendarPicker.formatIsoDate(to)}`;
      }
    }
    rangeSelect.value = this.currentRange;
  },

  updateSiteSelect(sites) {
    const select = document.getElementById("siteSelect");
    if (!select || !Array.isArray(sites)) return;

    let html = "";
    sites.forEach((s) => {
      const sId = typeof s === "object" ? s.id : s;
      const sName = typeof s === "object" ? s.name || s.id : s;
      const selected = sId === this.currentSiteId ? "selected" : "";
      html += `<option value="${this.escapeHtml(sId)}" ${selected}>${this.escapeHtml(sName)}</option>`;
    });

    select.innerHTML = html;
    if (this.currentSiteId) {
      select.value = this.currentSiteId;
    }
  },

  bindModals() {
    // Close modal buttons
    document.querySelectorAll("[data-close-modal]").forEach((btn) => {
      btn.addEventListener("click", () => {
        document
          .querySelectorAll(".modal-overlay")
          .forEach((m) => m.classList.remove("active"));
      });
    });

    // Click outside modal dialog to close
    document.querySelectorAll(".modal-overlay").forEach((overlay) => {
      overlay.addEventListener("click", (e) => {
        // Windows with several steps only close on purpose, not on a stray click.
        if (e.target === overlay && !("staticBackdrop" in overlay.dataset)) {
          overlay.classList.remove("active");
        }
      });
    });

    // Escape key to close
    window.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      document
        .querySelectorAll(".modal-overlay.active")
        .forEach((m) => m.classList.remove("active"));
    });
  },

  selectSiteAndOpen(siteId) {
    if (!siteId) return;
    this.currentSiteId = siteId;
    sessionStorage.setItem("petitstats_current_site", siteId);
    this.updateSidebarSiteInfo(siteId);

    const url = new URL(window.location);
    url.searchParams.set("site", siteId);
    url.hash = "#overview";
    window.history.pushState({}, "", url);

    this.navigateTo("overview");
  },

  async updateSidebarSiteInfo(siteId) {
    if (!siteId) return;

    // Update sidebar navigation links to include ?site= parameter
    document.querySelectorAll(".sidebar-nav .nav-item[data-page]").forEach((item) => {
      const page = item.dataset.page;
      const anchor = item.querySelector("a");
      if (anchor && page) {
        anchor.href = `?site=${encodeURIComponent(siteId)}#${page}`;
      }
    });

    let site = (WebsitesPage.sites || []).find((s) => s.id === siteId);
    if (!site) {
      try {
        const res = await Api.getSites();
        WebsitesPage.sites = res.sites || [];
        site = (WebsitesPage.sites || []).find((s) => s.id === siteId);
      } catch (e) {
        // Ignore
      }
    }

    const siteName = site ? site.name || site.id : siteId;
    const siteDomain = site && site.domain ? site.domain : siteId;

    const nameEl = document.getElementById("sidebarSiteName");
    const domEl = document.getElementById("sidebarSiteDomain");
    const logoImg = document.getElementById("sidebarSiteLogo");
    const fallbackEl = document.getElementById("sidebarSiteFallback");

    if (nameEl) nameEl.textContent = siteName;
    if (domEl) domEl.textContent = siteDomain;

    const siteBox = document.getElementById("sidebarSiteBox");
    if (siteBox) {
      siteBox.setAttribute("title", `${siteName} (${siteDomain})`);
    }

    if (logoImg && fallbackEl) {
      const cleanDomain = (siteDomain || "")
        .replace(/^https?:\/\//, "")
        .replace(/\/.*$/, "")
        .split(":")[0]
        .trim();
      if (
        cleanDomain &&
        cleanDomain !== "localhost" &&
        cleanDomain !== "127.0.0.1" &&
        cleanDomain.includes(".")
      ) {
        logoImg.onload = () => {
          logoImg.style.display = "block";
          fallbackEl.style.display = "none";
        };
        logoImg.onerror = () => {
          logoImg.style.display = "none";
          fallbackEl.style.display = "flex";
        };
        logoImg.src = `https://www.google.com/s2/favicons?domain=${encodeURIComponent(cleanDomain)}&sz=64`;
      } else {
        logoImg.style.display = "none";
        fallbackEl.style.display = "flex";
      }
    }
  },

  async handleRoute() {
    const urlParams = new URLSearchParams(window.location.search);
    let siteParam = urlParams.get("site") || urlParams.get("site_id");
    let hash = window.location.hash.replace("#", "").trim();

    // Guests never leave the public site: no websites portal, no settings.
    if (this.isGuest) {
      siteParam = this.guestSiteId;
      if (!hash || hash === "websites" || hash === "settings") {
        hash = "overview";
        const url = new URL(window.location);
        url.searchParams.set("site", siteParam);
        url.hash = "#overview";
        window.history.replaceState({}, "", url);
      }
    }

    // If explicit back to websites
    if (hash === "websites") {
      this.currentSiteId = null;
      sessionStorage.removeItem("petitstats_current_site");
      const url = new URL(window.location);
      url.searchParams.delete("site");
      url.searchParams.delete("site_id");
      window.history.replaceState({}, "", url);
      this.navigateTo("websites");
      return;
    }

    if (hash === "settings") {
      this.currentSiteId = null;
      sessionStorage.removeItem("petitstats_current_site");
      const url = new URL(window.location);
      url.searchParams.delete("site");
      url.searchParams.delete("site_id");
      window.history.replaceState({}, "", url);
      this.navigateTo("settings");
      return;
    }

    // A bare /dashboard opens the websites portal, not the last site viewed.
    if (!hash && !siteParam) {
      this.returnToWebsites();
      return;
    }

    // Check memory or sessionStorage if not in query string
    if (!siteParam || siteParam === "all") {
      const saved = this.currentSiteId || sessionStorage.getItem("petitstats_current_site");
      if (saved && saved !== "all") {
        siteParam = saved;
      }
    }

    if (siteParam && siteParam !== "all") {
      try {
        const res = await Api.getSites();
        const matchingSite = (res.sites || []).find((site) => site.id === siteParam);
        if (!matchingSite) {
          this.returnToWebsites();
          return;
        }
      } catch (e) {
        this.returnToWebsites();
        return;
      }

      this.currentSiteId = siteParam;
      sessionStorage.setItem("petitstats_current_site", siteParam);

      // Keep ?site= parameter in the browser URL
      const url = new URL(window.location);
      if (url.searchParams.get("site") !== siteParam) {
        url.searchParams.set("site", siteParam);
        window.history.replaceState({}, "", url);
      }

      const targetPage = !hash ? "overview" : hash;
      if (window.location.hash !== `#${targetPage}`) {
        window.location.hash = `#${targetPage}`;
      }
      this.navigateTo(targetPage);
      return;
    }

    // No site selected anywhere
    if (hash === "overview" || hash === "acquisition" || hash === "sessions" || hash === "events" || hash === "funnels" || hash === "revenue") {
      try {
        const res = await Api.getSites();
        const sites = res.sites || [];
        if (!sites[0]) {
          this.navigateTo("websites");
          return;
        }
        const firstSite = sites[0].id;
        this.currentSiteId = firstSite;
        sessionStorage.setItem("petitstats_current_site", firstSite);

        const url = new URL(window.location);
        url.searchParams.set("site", firstSite);
        window.history.replaceState({}, "", url);

        this.navigateTo(hash);
      } catch (e) {
        this.navigateTo("websites");
      }
      return;
    }

    // Default to websites portal page
    this.returnToWebsites();
  },

  navigateTo(pageName) {
    if (!["websites", "overview", "acquisition", "events", "sessions", "funnels", "revenue", "settings"].includes(pageName)) {
      pageName = "websites";
    }

    this.currentPage = pageName;

    const appContainer = document.querySelector(".app-container");
    if (appContainer) {
      if (pageName === "websites" || pageName === "settings") {
        appContainer.classList.add("is-portal");
      } else {
        appContainer.classList.remove("is-portal");
        // Ensure sidebar info is updated for active site
        if (this.currentSiteId) {
          this.updateSidebarSiteInfo(this.currentSiteId);
        }
      }
    }

    // Update nav active state
    document.querySelectorAll(".nav-item").forEach((item) => {
      const isActive = item.dataset.page === pageName;
      item.classList.toggle("active", isActive);
      const link = item.matches("a") ? item : item.querySelector("a");
      if (!link) return;
      if (isActive) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });

    // Update views visibility
    document.querySelectorAll(".page-view").forEach((view) => {
      view.style.display = ""; // remove any inline display style override
      if (view.id === `page-${pageName}`) {
        view.classList.add("active");
      } else {
        view.classList.remove("active");
      }
    });

    if (pageName !== "overview") {
      const noDataEl = document.getElementById("no-data-yet");
      if (noDataEl) noDataEl.style.display = "none";
    }

    // Update header title
    const titles = {
      websites: "Websites & Projects",
      overview: "Analytics Overview",
      acquisition: "Acquisition",
      events: "Events Stream",
      sessions: "User Sessions",
      funnels: "Funnels",
      revenue: "Revenue",
      settings: "Settings",
    };
    const titleElem = document.getElementById("headerPageTitle");
    if (titleElem) {
      titleElem.textContent = titles[pageName] || "Dashboard";
    }

    // Load data for the active page
    this.refreshCurrentPage();
  },

  refreshCurrentPage(silent = false) {
    if (this.currentPage === "websites") {
      WebsitesPage.load();
    } else if (this.currentPage === "overview") {
      OverviewPage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "acquisition") {
      AcquisitionPage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "events") {
      EventsPage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "sessions") {
      SessionsPage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "funnels") {
      FunnelsPage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "revenue") {
      RevenuePage.load(this.currentRange, this.currentSiteId, this.customDates);
    } else if (this.currentPage === "settings") {
      SettingsPage.load();
    }
  },

  returnToWebsites() {
    if (this.isGuest) {
      this.navigateTo("overview");
      return;
    }
    this.currentSiteId = null;
    sessionStorage.removeItem("petitstats_current_site");
    const url = new URL(window.location);
    url.searchParams.delete("site");
    url.searchParams.delete("site_id");
    url.hash = "#websites";
    window.history.replaceState({}, "", url);
    this.navigateTo("websites");
  },

  setLoading(isLoading, message = "Loading analytics data...") {
    const bar = document.getElementById("appLoadingBar");
    const banner = document.getElementById("overviewLoadingBanner");
    const refreshBtn = document.getElementById("btnRefresh");
    const container = document.getElementById("overviewDataContainer");

    if (isLoading) {
      if (bar) {
        bar.classList.remove("finishing");
        bar.classList.add("active");
      }
      if (refreshBtn) {
        refreshBtn.classList.add("btn-refresh-spinning");
      }
      if (container) {
        container.classList.add("is-loading");
      }
      // If query takes longer than 180ms, reveal the floating banner
      clearTimeout(this._loadingBannerTimer);
      this._loadingBannerTimer = setTimeout(() => {
        if (bar && bar.classList.contains("active") && banner) {
          banner.style.display = "inline-flex";
          const bannerText = banner.querySelector("span");
          if (bannerText) bannerText.textContent = message;
        }
      }, 180);
    } else {
      clearTimeout(this._loadingBannerTimer);
      if (banner) {
        banner.style.display = "none";
      }
      if (container) {
        container.classList.remove("is-loading");
      }
      if (refreshBtn) {
        refreshBtn.classList.remove("btn-refresh-spinning");
      }
      if (bar) {
        bar.classList.add("finishing");
        setTimeout(() => {
          bar.classList.remove("active", "finishing");
        }, 220);
      }
    }
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

  async displayNoDataMessage(siteId) {
    const noDataEl = document.getElementById("no-data-yet");
    if (noDataEl) noDataEl.style.display = "flex";

    const overviewData = document.getElementById("overviewDataContainer");
    if (overviewData) overviewData.style.display = "none";

    const host = window.location.host;
    const protocol = window.location.protocol;
    const scriptUrl = `${protocol}//${host}/petitstats.js`;
    let key = "";
    try { key = (await Api.getTrackingConfig(siteId)).site.write_key; } catch (e) { return; }
    const snippet = `<script defer src="${scriptUrl}" data-site-id="${siteId}" data-site-key="${key}" data-privacy-mode="strict"></script>`;

    const overviewSnippet = document.getElementById("overviewSnippetPre");
    if (overviewSnippet) {
      overviewSnippet.dataset.rawText = snippet;
      if (
        window.ClipboardHelper &&
        typeof window.ClipboardHelper.highlightHtml === "function"
      ) {
        overviewSnippet.innerHTML =
          window.ClipboardHelper.highlightHtml(snippet);
      } else {
        overviewSnippet.textContent = snippet;
      }
    }

    const createdSnippet = document.getElementById("createdSiteSnippet");
    if (createdSnippet && !createdSnippet.dataset.rawText) {
      createdSnippet.dataset.rawText = snippet;
      if (
        window.ClipboardHelper &&
        typeof window.ClipboardHelper.highlightHtml === "function"
      ) {
        createdSnippet.innerHTML =
          window.ClipboardHelper.highlightHtml(snippet);
      } else {
        createdSnippet.textContent = snippet;
      }
    }
  },
};

const ClipboardHelper = {
  copy(text, btnElement) {
    if (!text) return;

    const doSuccess = () => {
      if (!btnElement) return;
      const copyIcon = btnElement.querySelector(".copy-icon");
      const checkIcon = btnElement.querySelector(".check-icon");
      const copyText = btnElement.querySelector(".copy-text");

      btnElement.classList.add("copied");
      if (copyIcon) copyIcon.style.display = "none";
      if (checkIcon) checkIcon.style.display = "inline-block";
      if (copyText) copyText.textContent = "Copied!";

      clearTimeout(btnElement._copyTimer);
      btnElement._copyTimer = setTimeout(() => {
        btnElement.classList.remove("copied");
        if (copyIcon) copyIcon.style.display = "inline-block";
        if (checkIcon) checkIcon.style.display = "none";
        if (copyText) copyText.textContent = "Copy";
      }, 2000);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard
        .writeText(text)
        .then(doSuccess)
        .catch(() => {
          this.fallbackCopy(text, doSuccess);
        });
    } else {
      this.fallbackCopy(text, doSuccess);
    }
  },

  fallbackCopy(text, callback) {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.top = "-9999px";
    ta.style.left = "-9999px";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      document.execCommand("copy");
      if (callback) callback();
    } catch (e) {
      console.error("Copy failed", e);
    }
    document.body.removeChild(ta);
  },

  highlightJson(jsonStr) {
    if (typeof jsonStr !== "string") {
      jsonStr = JSON.stringify(jsonStr, null, 2);
    }
    const escaped = jsonStr
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");

    return escaped.replace(
      /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
      (match) => {
        let cls = "json-number";
        if (/^"/.test(match)) {
          if (/:$/.test(match)) {
            cls = "json-key";
          } else {
            cls = "json-string";
          }
        } else if (/true|false/.test(match)) {
          cls = "json-boolean";
        } else if (/null/.test(match)) {
          cls = "json-null";
        }
        return `<span class="${cls}">${match}</span>`;
      },
    );
  },

  highlightHtml(htmlStr) {
    const escaped = String(htmlStr)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");

    return (
      escaped
        // .replace(/(&lt;\/?[a-z0-9-]+|&gt;)/gi, '<span class="html-tag">$1</span>')
        .replace(
          /([a-z0-9_-]+)=(&quot;.*?&quot;|&#39;.*?&#39;|".*?"|'.*?')/gi,
          '<span class="html-attr">$1</span>=<span class="html-val">$2</span>',
        )
    );
  },

  init() {
    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".btn-copy-code");
      if (!btn) return;
      e.preventDefault();
      const targetSelector = btn.dataset.copyTarget;
      let textToCopy = "";
      if (targetSelector) {
        const target = document.querySelector(targetSelector);
        if (target) {
          textToCopy =
            target.dataset.rawText || target.innerText || target.textContent;
        }
      } else if (btn.dataset.copyText) {
        textToCopy = btn.dataset.copyText;
      }
      if (textToCopy) {
        this.copy(textToCopy.trim(), btn);
      }
    });
  },
};

window.ClipboardHelper = ClipboardHelper;

document.addEventListener("DOMContentLoaded", () => {
  App.init();
});

window.App = App;
