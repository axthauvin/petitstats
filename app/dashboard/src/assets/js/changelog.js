/**
 * Release notes from GitHub, shown before an update (Settings → Updates) and
 * once after it, in the "What's new" window.
 */
const Changelog = {
  STORAGE_KEY: "petitstats:seen-version",
  LABELS: { new: "New", fixed: "Fixed", improved: "Improved" },

  /** A list of releases, newest first, each with its changes grouped as new, fixed and improved. */
  render(releases) {
    const list = document.createElement("ol");
    list.className = "changelog";
    for (const release of releases) {
      const item = document.createElement("li");
      item.className = "changelog-release";
      const header = document.createElement("div");
      header.className = "changelog-release-header";
      const version = Object.assign(document.createElement("a"), { className: "changelog-version", href: release.url, target: "_blank", rel: "noopener", textContent: release.version });
      header.append(version);
      const date = this.formatDate(release.published_at);
      if (date) header.append(Object.assign(document.createElement("time"), { className: "changelog-date", dateTime: release.published_at, textContent: date }));
      item.append(header);

      const changes = document.createElement("ul");
      changes.className = "changelog-changes";
      const order = Object.keys(this.LABELS);
      const sorted = [...release.changes].sort((a, b) => order.indexOf(a.type) - order.indexOf(b.type));
      for (const change of sorted) {
        const row = document.createElement("li");
        const badge = Object.assign(document.createElement("span"), { className: `changelog-badge changelog-badge-${change.type}`, textContent: this.LABELS[change.type] || change.type });
        const text = change.url
          ? Object.assign(document.createElement("a"), { href: change.url, target: "_blank", rel: "noopener", textContent: change.text })
          : Object.assign(document.createElement("span"), { textContent: change.text });
        text.classList.add("changelog-text");
        row.append(badge, text);
        changes.append(row);
      }
      if (!sorted.length) changes.append(Object.assign(document.createElement("li"), { className: "changelog-empty", textContent: "No details for this release." }));
      item.append(changes);
      list.append(item);
    }
    return list;
  },

  formatDate(iso) {
    const date = iso ? new Date(iso) : null;
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" }) : "";
  },

  /**
   * Opens "What's new" once after Petitstats was updated. Each browser remembers
   * the last version it saw; a first visit only records it.
   */
  async showWhatsNewOnce(installed) {
    if (!installed) return;
    let seen = null;
    try { seen = localStorage.getItem(this.STORAGE_KEY); localStorage.setItem(this.STORAGE_KEY, installed); } catch { return; }
    if (!seen || seen === installed) return;
    const data = await Api.getChangelog({ after: seen, upTo: installed }).catch(() => null);
    if (!data || !data.releases.length) return;
    const modal = document.getElementById("whatsNewModal"); if (!modal) return;
    document.getElementById("whatsNewTitle").textContent = `What's new in Petitstats ${installed}`;
    document.getElementById("whatsNewBody").replaceChildren(this.render(data.releases));
    modal.classList.add("active");
  },
};
