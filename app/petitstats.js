(function () {
    "use strict";
    var script = document.currentScript;
    var siteId = script && script.getAttribute("data-site-id");
    var siteKey = script && script.getAttribute("data-site-key");
    var endpoint =
        (script && script.getAttribute("data-endpoint")) ||
        (script && script.src
            ? new URL(script.src, location.href).origin + "/track.php"
            : "/track.php");
    var autoTrack =
        !script || script.getAttribute("data-auto-track") !== "false";
    // Real visitors move, scroll, touch, type, or at least look at the page.
    // Events are held back until the first interaction or until the page has
    // been visible for VISIBLE_MS, which keeps out scripts that merely load the
    // page, prerendered pages and tabs opened in the background.
    var requireInteraction =
        !script || script.getAttribute("data-require-interaction") !== "false";
    var VISIBLE_MS = 3000;
    var interacted = false,
        visibleMs = 0,
        visibleSince = null,
        visibleTimer = null,
        seen = false,
        pendingPageview = null,
        pendingEvents = [];
    var debug = !!script && script.getAttribute("data-debug") === "true";
    var privacyMode =
        script && script.getAttribute("data-privacy-mode") === "enriched"
            ? "enriched"
            : "strict";
    var consentGranted =
        !!script && script.getAttribute("data-consent") === "granted";
    var SESSION_KEY = "petitstats_session_v2_" + siteId;
    var LAST_KEY = SESSION_KEY + "_last";
    var lastPath = null,
        engaged = false,
        configurationReported = false;

    function reportError(message, details) {
        if (debug && window.console && window.console.error)
            window.console.error("[Petitstats] " + message, details || "");
    }
    function reportDebug(message, details) {
        if (debug && window.console && window.console.debug)
            window.console.debug("[Petitstats] " + message, details || "");
    }

    function localOptOutReason() {
        try {
            // "minilytics_opt_out" was set before the project was renamed.
            if (
                localStorage.getItem("petitstats_opt_out") === "true" ||
                localStorage.getItem("minilytics_opt_out") === "true"
            )
                return "local_storage";
        } catch (_) {}
        return null;
    }
    var DOCUMENT_DRIVER_KEYS = [
        "__webdriver_evaluate",
        "__selenium_evaluate",
        "__webdriver_script_function",
        "__webdriver_script_func",
        "__webdriver_script_fn",
        "__fxdriver_evaluate",
        "__driver_unwrapped",
        "__webdriver_unwrapped",
        "__driver_evaluate",
        "__selenium_unwrapped",
        "__fxdriver_unwrapped",
    ];
    var WINDOW_DRIVER_KEYS = [
        "_phantom",
        "__nightmare",
        "_selenium",
        "callPhantom",
        "callSelenium",
        "_Selenium_IDE_Recorder",
    ];
    var DRIVER_ATTRIBUTES = ["selenium", "webdriver", "driver"];

    // Detection keys based on https://stackoverflow.com/a/41220267 (CC BY-SA 4.0)
    function automatedBrowserReason() {
        if (navigator.webdriver === true) return "webdriver";
        var key;
        for (var i = 0; i < WINDOW_DRIVER_KEYS.length; i++) {
            key = WINDOW_DRIVER_KEYS[i];
            if (window[key]) return key;
        }
        for (i = 0; i < DOCUMENT_DRIVER_KEYS.length; i++) {
            key = DOCUMENT_DRIVER_KEYS[i];
            if (document[key]) return key;
        }
        for (key in document) {
            // ChromeDriver injects a $cdc_... object into the document.
            if (/\$[a-z]dc_/.test(key) && document[key] && document[key].cache_)
                return key;
        }
        try {
            if (String(window.external).indexOf("Sequentum") !== -1)
                return "Sequentum";
        } catch (_) {}
        var root = document.documentElement;
        for (i = 0; root && i < DRIVER_ATTRIBUTES.length; i++) {
            if (root.getAttribute(DRIVER_ATTRIBUTES[i]))
                return DRIVER_ATTRIBUTES[i];
        }
        return inconsistentBrowserReason();
    }
    // Properties a real browser always agrees on, and that headless or
    // spoofed browsers often get wrong. Each check only fires on a clear
    // contradiction, since a false positive hides a real visitor.
    function inconsistentBrowserReason() {
        try {
            var ua = navigator.userAgent || "";
            var platform = navigator.platform || "";
            if (navigator.languages && navigator.languages.length === 0)
                return "no_languages";
            if (window.screen && screen.width === 0 && screen.height === 0)
                return "no_screen";
            // Desktop Chrome always has window.chrome; Android WebViews and
            // iOS browsers do not, so mobile user agents are left alone.
            if (
                /Chrome\//.test(ua) &&
                !/Mobile|Android|CriOS/.test(ua) &&
                !window.chrome
            )
                return "no_chrome_object";
            if (/iPhone|Android.*Mobile/.test(ua) && navigator.maxTouchPoints === 0)
                return "mobile_without_touch";
            if (
                (/Windows/.test(ua) && platform && !/^Win/.test(platform)) ||
                (/Macintosh/.test(ua) && platform && !/^Mac/.test(platform))
            )
                return "platform_mismatch";
        } catch (_) {}
        return null;
    }
    // Lets the server log the detection (without storing any analytics event),
    // so that it shows up in the dashboard's bot report. Sent once per page.
    var botReported = false;
    function reportBot(reason) {
        if (botReported || !siteId || !siteKey) return;
        botReported = true;
        var payload = JSON.stringify({
            site_id: siteId,
            site_key: siteKey,
            name: "_ml_bot",
            data: { reason: reason },
        });
        // fetch rather than sendBeacon: beacons are sent with credentials,
        // which the tracking endpoint's CORS policy does not allow.
        try {
            if (typeof fetch === "function")
                fetch(endpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: payload,
                    keepalive: true,
                }).catch(function () {});
        } catch (_) {}
    }
    function browserPrivacyReason() {
        if (navigator.globalPrivacyControl === true)
            return "global_privacy_control";
        if (navigator.doNotTrack === "1") return "do_not_track";
        return null;
    }
    function optedOut() {
        return (
            localOptOutReason() !== null || automatedBrowserReason() !== null
        );
    }
    function id() {
        return typeof crypto !== "undefined" && crypto.randomUUID
            ? crypto.randomUUID().replace(/-/g, "")
            : Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    // Strict mode stores nothing in the browser and sends no identifier: the
    // server recognises the visitor from a hash that changes every month.
    // Enriched mode keeps the visit in sessionStorage once consent is granted,
    // so it survives a network change.
    function sessionId() {
        if (privacyMode === "strict" || !consentGranted) return null;
        try {
            var last = Number(sessionStorage.getItem(LAST_KEY) || 0);
            var value = sessionStorage.getItem(SESSION_KEY);
            if (!value || Date.now() - last > 30 * 60 * 1000) {
                value = id();
                sessionStorage.setItem(SESSION_KEY, value);
            }
            sessionStorage.setItem(LAST_KEY, String(Date.now()));
            return value;
        } catch (_) {
            return id();
        }
    }
    function campaign() {
        var p = new URLSearchParams(location.search),
            r = {};
        [
            "utm_source",
            "utm_medium",
            "utm_campaign",
            "utm_content",
            "utm_term",
        ].forEach(function (k) {
            if (p.has(k)) r[k] = p.get(k).slice(0, 200);
        });
        return r;
    }
    function context() {
        var screenSize =
            window.screen && screen.width && screen.height
                ? screen.width + "×" + screen.height
                : null;
        var viewport =
            window.innerWidth && window.innerHeight
                ? window.innerWidth + "×" + window.innerHeight
                : null;
        var base = {
            tracking_mode: privacyMode,
            path: location.pathname || "/",
            title: document.title.slice(0, 300),
            hostname: location.hostname,
            referrer: document.referrer
                ? new URL(document.referrer).hostname
                : null,
            language: navigator.language || null,
            // Screen resolution is coarse, useful aggregate context and does not
            // require browser storage. Keep it available in strict mode too.
            screen: screenSize,
        };
        if (privacyMode === "enriched") {
            base.viewport = viewport;
        }
        return Object.assign(base, campaign());
    }
    function sanitise(data) {
        var out = {};
        Object.keys(data || {}).forEach(function (k) {
            if (!/password|token|secret|email|phone|address|card/i.test(k))
                out[k] = data[k];
        });
        return out;
    }
    var INTERACTION_EVENTS = [
        "pointermove",
        "pointerdown",
        "keydown",
        "wheel",
        "scroll",
        "touchstart",
    ];
    function onInteraction(e) {
        if (interacted || !e.isTrusted) return;
        confirmVisitor("First interaction detected, sending the held events.");
    }
    function confirmVisitor(message) {
        interacted = true;
        clearTimeout(visibleTimer);
        INTERACTION_EVENTS.forEach(function (type) {
            removeEventListener(type, onInteraction, true);
        });
        reportDebug(message, {
            pageview: !!pendingPageview,
            events: pendingEvents.length,
        });
        var held = pendingEvents;
        pendingEvents = [];
        if (pendingPageview) {
            var data = pendingPageview.data;
            pendingPageview = null;
            pageview(data);
        }
        held.forEach(function (event) {
            send(event.name, event.data);
        });
    }
    function pageVisible() {
        if (document.prerendering) return false;
        return document.visibilityState
            ? document.visibilityState === "visible"
            : !document.hidden;
    }
    // Adds up the time the page is actually on screen, and confirms the
    // visitor once it reaches VISIBLE_MS even if they never interact.
    function onVisibilityChange() {
        if (interacted) return;
        var now = Date.now();
        if (visibleSince !== null) visibleMs += now - visibleSince;
        visibleSince = null;
        clearTimeout(visibleTimer);
        if (!pageVisible()) return;
        seen = true;
        visibleSince = now;
        visibleTimer = setTimeout(function () {
            if (!interacted && pageVisible())
                confirmVisitor(
                    "Page visible long enough, sending the held events.",
                );
        }, Math.max(0, VISIBLE_MS - visibleMs));
    }
    if (requireInteraction) {
        INTERACTION_EVENTS.forEach(function (type) {
            addEventListener(type, onInteraction, {
                capture: true,
                passive: true,
            });
        });
        document.addEventListener("visibilitychange", onVisibilityChange);
        document.addEventListener("prerenderingchange", onVisibilityChange);
        // A page seen but left before either signal is not counted, but is
        // logged so that the dashboard shows how many visits this leaves out.
        addEventListener("pagehide", function () {
            if (!interacted && seen && pendingPageview)
                reportBot("no_interaction");
        });
        onVisibilityChange();
    }
    function send(name, data) {
        if (requireInteraction && !interacted && siteId && siteKey) {
            // Only the latest page view matters; other events are kept in order.
            if (name === "pageview") pendingPageview = { data: data };
            else if (pendingEvents.length < 20)
                pendingEvents.push({ name: name, data: data });
            reportDebug("Event held until the first user interaction.", {
                event: name,
            });
            return false;
        }
        if (!siteId || !siteKey) {
            if (!configurationReported) {
                configurationReported = true;
                reportError(
                    "Tracking is not configured: both data-site-id and data-site-key are required.",
                    {
                        endpoint: endpoint,
                        hasSiteId: !!siteId,
                        hasSiteKey: !!siteKey,
                    },
                );
            }
            return false;
        }
        var optOutReason = localOptOutReason();
        if (optOutReason) {
            reportDebug("Event was not sent because tracking is opted out.", {
                event: name,
                reason: optOutReason,
                resolution:
                    optOutReason === "local_storage"
                        ? "For this browser profile, run petitstats.optIn() and reload the page."
                        : "Disable this browser privacy preference only if you want to test tracking.",
            });
            return false;
        }
        var automatedReason = automatedBrowserReason();
        if (automatedReason) {
            reportBot(automatedReason);
            reportDebug(
                "Event was not sent because an automated browser was detected.",
                {
                    event: name,
                    reason: automatedReason,
                },
            );
            return false;
        }
        var privacyReason = browserPrivacyReason();
        if (privacyMode === "enriched" && !consentGranted) {
            reportDebug(
                "Event was not sent because enriched tracking requires consent.",
                {
                    event: name,
                    resolution:
                        "Call petitstats.consent() from your cookie banner after the user accepts analytics cookies.",
                },
            );
            return false;
        }
        if (privacyMode === "enriched" && privacyReason) {
            reportDebug(
                "Event was not sent because the browser privacy preference blocks enriched tracking.",
                { event: name, reason: privacyReason },
            );
            return false;
        }
        if (privacyMode === "strict" && privacyReason)
            reportDebug(
                "Browser privacy preference detected; only cookieless, anonymous analytics will be sent.",
                { event: name, reason: privacyReason },
            );
        var payload = JSON.stringify({
            site_id: siteId,
            site_key: siteKey,
            session_id: sessionId(),
            name: name,
            data: Object.assign(context(), sanitise(data)),
        });
        var metadata = { event: name, endpoint: endpoint };
        if (typeof fetch === "function") {
            try {
                fetch(endpoint, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: payload,
                    keepalive: true,
                })
                    .then(function (response) {
                        if (response.ok) {
                            reportDebug(
                                "Event accepted by the tracking endpoint.",
                                metadata,
                            );
                            return;
                        }
                        response
                            .text()
                            .then(function (body) {
                                reportError(
                                    "Tracking endpoint rejected an event.",
                                    Object.assign(
                                        {
                                            status: response.status,
                                            statusText: response.statusText,
                                            response: body.slice(0, 500),
                                        },
                                        metadata,
                                    ),
                                );
                            })
                            .catch(function () {
                                reportError(
                                    "Tracking endpoint rejected an event.",
                                    Object.assign(
                                        {
                                            status: response.status,
                                            statusText: response.statusText,
                                        },
                                        metadata,
                                    ),
                                );
                            });
                    })
                    .catch(function (error) {
                        reportError(
                            "Tracking request failed. Check the endpoint URL, CORS policy, and network connection.",
                            Object.assign(
                                {
                                    error:
                                        error && error.message
                                            ? error.message
                                            : String(error),
                                },
                                metadata,
                            ),
                        );
                    });
            } catch (error) {
                reportError(
                    "Tracking request could not be started.",
                    Object.assign(
                        {
                            error:
                                error && error.message
                                    ? error.message
                                    : String(error),
                        },
                        metadata,
                    ),
                );
                return false;
            }
            return true;
        } else if (navigator.sendBeacon) {
            if (
                navigator.sendBeacon(
                    endpoint,
                    new Blob([payload], { type: "application/json" }),
                )
            ) {
                reportDebug(
                    "Event queued with sendBeacon; the server response cannot be inspected by this browser API.",
                    metadata,
                );
                return true;
            } else {
                reportError(
                    "Tracking request could not be queued by sendBeacon.",
                    metadata,
                );
                return false;
            }
        } else {
            reportError(
                "Tracking is unavailable: this browser supports neither fetch nor sendBeacon.",
                metadata,
            );
            return false;
        }
    }
    function pageview(data) {
        var path = location.pathname + location.search;
        if (path !== lastPath) {
            if (send("pageview", data)) lastPath = path;
        }
    }
    var api = {
        track: function (name, data) {
            name === "pageview" || !name ? pageview(data) : send(name, data);
        },
        pageview: pageview,
        // Only events sent this way count as revenue: a "revenue" property on
        // an ordinary event stays a plain property.
        revenue: function (name, amount, data) {
            var value = Number(amount);
            if (!name || !isFinite(value) || value <= 0) {
                reportError(
                    "petitstats.revenue() expects an event name and a positive amount.",
                    { event: name, amount: amount },
                );
                return;
            }
            var d = Object.assign({}, data);
            d._ml_revenue = value;
            send(String(name), d);
        },
        optOut: function () {
            try {
                localStorage.setItem("petitstats_opt_out", "true");
            } catch (_) {}
        },
        optIn: function () {
            try {
                localStorage.removeItem("petitstats_opt_out");
                localStorage.removeItem("minilytics_opt_out");
            } catch (_) {}
        },
        consent: function () {
            if (privacyMode !== "enriched") return false;
            if (browserPrivacyReason()) {
                reportDebug(
                    "Enriched tracking remains disabled because of the browser privacy preference.",
                    { reason: browserPrivacyReason() },
                );
                return false;
            }
            consentGranted = true;
            pageview();
            return true;
        },
        withdrawConsent: function () {
            consentGranted = false;
        },
        privacyMode: privacyMode,
        sessionId: sessionId,
        siteId: siteId,
    };
    window.petitstats = api;
    // Snippets written before the project was renamed call window.minilytics.
    window.minilytics = api;
    var automated = automatedBrowserReason();
    if (automated && autoTrack) reportBot(automated);
    if (!autoTrack || optedOut()) return;
    if (document.readyState === "complete") pageview();
    else addEventListener("load", pageview, { once: true });
    ["pushState", "replaceState"].forEach(function (method) {
        var original = history[method];
        if (original)
            history[method] = function () {
                var r = original.apply(this, arguments);
                pageview();
                return r;
            };
    });
    addEventListener("popstate", pageview);
    addEventListener("hashchange", pageview);
    setTimeout(function () {
        if (!engaged && !document.hidden) {
            engaged = true;
            send("_ml_engaged");
        }
    }, 10000);
    document.addEventListener("visibilitychange", function () {
        if (document.hidden && !engaged) {
            engaged = true;
            send("_ml_engaged");
        }
    });
    document.addEventListener("click", function (e) {
        // data-minilytics-* attributes come from pages tagged before the project was renamed.
        var el = e.target.closest("[data-petitstats-event], [data-minilytics-event]");
        if (el) {
            var prefix = el.hasAttribute("data-petitstats-event")
                ? "data-petitstats-"
                : "data-minilytics-";
            var d = {};
            Array.prototype.forEach.call(el.attributes, function (a) {
                if (
                    a.name.indexOf(prefix) === 0 &&
                    a.name !== prefix + "event"
                )
                    d[a.name.slice(prefix.length)] = a.value;
            });
            send(el.getAttribute(prefix + "event"), d);
            return;
        }
        var link = e.target.closest("a");
        if (!link || !link.href) return;
        try {
            var u = new URL(link.href, location.href);
            if (u.hostname !== location.hostname)
                send("outbound_click", {
                    target_host: u.hostname,
                    link_text: (link.innerText || "").trim().slice(0, 100),
                });
            else if (
                /\.(pdf|zip|csv|xlsx|docx|pptx|mp3|mp4)$/i.test(u.pathname)
            )
                send("file_download", {
                    file_name: u.pathname.split("/").pop(),
                });
        } catch (_) {}
    });
})();
