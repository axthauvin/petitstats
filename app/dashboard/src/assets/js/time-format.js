/**
 * How the dashboard writes times: 12-hour (5:25 PM) or 24-hour (17:25).
 * The choice is kept in this browser; by default it follows the browser's language.
 */
const TimeFormat = {
  STORAGE_KEY: "petitstats_time_format",

  /** "12h" or "24h". */
  get() {
    try {
      const saved = localStorage.getItem(this.STORAGE_KEY);
      if (saved === "12h" || saved === "24h") return saved;
    } catch (_) {}
    const cycle = new Intl.DateTimeFormat(undefined, { hour: "numeric" }).resolvedOptions().hourCycle;
    return cycle === "h11" || cycle === "h12" ? "12h" : "24h";
  },

  set(format) {
    try {
      localStorage.setItem(this.STORAGE_KEY, format === "12h" ? "12h" : "24h");
    } catch (_) {}
  },

  /** Options for toLocaleTimeString / Intl.DateTimeFormat, with or without seconds. */
  options({ seconds = false } = {}) {
    const twelveHours = this.get() === "12h";
    return {
      hour: twelveHours ? "numeric" : "2-digit",
      minute: "2-digit",
      ...(seconds ? { second: "2-digit" } : {}),
      hourCycle: twelveHours ? "h12" : "h23",
    };
  },

  /** "5:25 PM" or "17:25", with seconds when asked. */
  time(date, { seconds = false } = {}) {
    return date.toLocaleTimeString("en-US", this.options({ seconds }));
  },

  /** An hour on a chart axis: "5 PM" or "17:00". */
  hour(date) {
    return this.get() === "12h"
      ? date.toLocaleTimeString("en-US", { hour: "numeric", hourCycle: "h12" })
      : date.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  },
};

window.TimeFormat = TimeFormat;
