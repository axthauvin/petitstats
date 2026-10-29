/* Petitstats documentation: mobile menu, copy buttons, table of contents and search. No dependency. */
(function () {
    "use strict";

    // Mobile navigation drawer.
    var menuToggle = document.querySelector(".menu-toggle");
    function setMenu(open) {
        document.body.classList.toggle("menu-open", open);
        if (menuToggle) menuToggle.setAttribute("aria-expanded", String(open));
    }
    if (menuToggle) menuToggle.addEventListener("click", function () { setMenu(!document.body.classList.contains("menu-open")); });
    document.querySelectorAll("[data-menu-close], .sidebar a").forEach(function (el) {
        el.addEventListener("click", function () { setMenu(false); });
    });

    // Copy buttons on code blocks.
    document.querySelectorAll(".code-block .copy").forEach(function (button) {
        button.addEventListener("click", function () {
            var code = button.parentElement.querySelector("code").innerText;
            navigator.clipboard.writeText(code).then(function () {
                button.classList.add("is-copied");
                setTimeout(function () { button.classList.remove("is-copied"); }, 1600);
            });
        });
    });

    // Highlight the section being read in "On this page".
    var tocLinks = Array.prototype.slice.call(document.querySelectorAll(".toc a"));
    if (tocLinks.length && "IntersectionObserver" in window) {
        var headings = tocLinks.map(function (link) { return document.getElementById(link.getAttribute("href").slice(1)); }).filter(Boolean);
        var visible = {};
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
            var current = headings.filter(function (h) { return visible[h.id]; })[0];
            if (!current) {
                // Between two headings: keep the last one above the viewport.
                current = headings.filter(function (h) { return h.getBoundingClientRect().top < 120; }).pop();
            }
            tocLinks.forEach(function (link) { link.classList.toggle("is-active", !!current && link.getAttribute("href") === "#" + current.id); });
        }, { rootMargin: "-70px 0px -65% 0px" });
        headings.forEach(function (h) { observer.observe(h); });
    }

    // Search: the index is a small script, loaded the first time the dialog opens.
    var modal = document.querySelector(".search-modal");
    if (!modal) return;
    var input = modal.querySelector("input");
    var list = modal.querySelector(".search-results");
    var selected = 0;
    var results = [];

    function loadIndex(callback) {
        if (window.PETITSTATS_DOCS_INDEX) return callback();
        var script = document.createElement("script");
        script.src = "/docs/_static/search-index.js";
        script.onload = callback;
        document.head.appendChild(script);
    }
    function openSearch() {
        modal.hidden = false;
        document.body.style.overflow = "hidden";
        input.value = "";
        render();
        input.focus();
        loadIndex(render);
    }
    function closeSearch() {
        modal.hidden = true;
        document.body.style.overflow = "";
    }
    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; });
    }
    function mark(text, terms) {
        var html = escapeHtml(text);
        terms.forEach(function (term) {
            html = html.replace(new RegExp("(" + escapeHtml(term).replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + ")", "gi"), "<mark>$1</mark>");
        });
        return html;
    }
    // A short excerpt of the text around the first match.
    function excerpt(text, terms) {
        var lower = text.toLowerCase();
        var at = Math.min.apply(null, terms.map(function (t) { var i = lower.indexOf(t); return i < 0 ? Infinity : i; }));
        if (!isFinite(at) || at < 40) return text.slice(0, 140);
        return "…" + text.slice(at - 30, at + 110);
    }
    function render() {
        var query = input.value.trim().toLowerCase();
        var terms = query.split(/\s+/).filter(Boolean);
        var index = window.PETITSTATS_DOCS_INDEX || [];
        results = !terms.length ? [] : index.map(function (entry) {
            var heading = entry.h.toLowerCase(), text = entry.x.toLowerCase(), page = entry.p.toLowerCase();
            var score = 0;
            for (var i = 0; i < terms.length; i++) {
                var t = terms[i], s = 0;
                if (heading.indexOf(t) === 0) s += 12; else if (heading.indexOf(t) > -1) s += 8;
                if (page.indexOf(t) > -1) s += 3;
                if (text.indexOf(t) > -1) s += 2;
                if (!s) return null;
                score += s;
            }
            return { entry: entry, score: score };
        }).filter(Boolean).sort(function (a, b) { return b.score - a.score; }).slice(0, 8);
        selected = 0;
        if (terms.length && !results.length) {
            list.innerHTML = '<li class="search-empty">No results for “' + escapeHtml(input.value.trim()) + '”</li>';
            return;
        }
        list.innerHTML = results.map(function (r, i) {
            var e = r.entry;
            return '<li><a href="' + escapeHtml(e.u) + '" role="option" aria-selected="' + (i === 0) + '">'
                + '<span class="result-page">' + escapeHtml(e.p) + '</span>'
                + '<span class="result-title">' + mark(e.h, terms) + '</span>'
                + '<span class="result-text">' + mark(excerpt(e.x, terms), terms) + '</span></a></li>';
        }).join("");
    }
    function select(next) {
        var links = list.querySelectorAll("a");
        if (!links.length) return;
        selected = (next + links.length) % links.length;
        links.forEach(function (a, i) { a.setAttribute("aria-selected", String(i === selected)); });
        links[selected].scrollIntoView({ block: "nearest" });
    }

    document.querySelectorAll("[data-search-open]").forEach(function (b) { b.addEventListener("click", openSearch); });
    input.addEventListener("input", render);
    list.addEventListener("click", closeSearch);
    modal.addEventListener("click", function (event) { if (event.target === modal) closeSearch(); });
    input.addEventListener("keydown", function (event) {
        if (event.key === "ArrowDown") { event.preventDefault(); select(selected + 1); }
        else if (event.key === "ArrowUp") { event.preventDefault(); select(selected - 1); }
        else if (event.key === "Enter") {
            var link = list.querySelectorAll("a")[selected];
            if (link) { closeSearch(); location.href = link.href; }
        }
    });
    document.addEventListener("keydown", function (event) {
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
        if ((event.key === "k" && (event.ctrlKey || event.metaKey)) || (event.key === "/" && !typing)) {
            event.preventDefault();
            modal.hidden ? openSearch() : closeSearch();
        } else if (event.key === "Escape" && !modal.hidden) {
            closeSearch();
        }
    });
})();
