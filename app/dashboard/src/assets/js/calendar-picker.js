/**
 * Small dependency-free calendar used by the dashboard's date controls.
 * Values remain ISO dates (YYYY-MM-DD), so the existing API contract is unchanged.
 */
class PetitstatsCalendarPicker {
  constructor(input) {
    this.input = typeof input === "string" ? document.getElementById(input) : input;
    if (!this.input) return;
    this.input.readOnly = true;
    this.input.setAttribute("autocomplete", "off");
    this.input.addEventListener("click", () => this.open());
    this.input.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        this.open();
      }
    });
    document.addEventListener("click", (event) => {
      if (this.popover && !this.popover.contains(event.target) && event.target !== this.input) this.close();
    });
    window.addEventListener("resize", () => this.position());
    document.addEventListener("scroll", () => this.position(), true);
  }

  open() {
    this.viewDate = this.parse(this.getValue()) || new Date();
    if (!this.popover) {
      this.popover = document.createElement("div");
      this.popover.className = "calendar-popover";
      this.popover.setAttribute("role", "dialog");
      this.popover.setAttribute("aria-label", "Calendar");
      // Rendering a new month replaces the clicked control. Stop the event at
      // the stable popover root so outside-click handlers never close it.
      this.popover.addEventListener("click", (event) => event.stopPropagation());
      document.body.appendChild(this.popover);
    }
    this.render();
    this.popover.hidden = false;
    this.position();
  }

  close() {
    if (this.popover) this.popover.hidden = true;
  }

  parse(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
    if (!match) return null;
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  }

  getValue() {
    return this.input.dataset.isoValue || this.input.value;
  }

  setValue(value) {
    const date = this.parse(value);
    if (!date) return this.clear();
    this.input.dataset.isoValue = value;
    this.input.value = PetitstatsCalendarPicker.formatIsoDate(value);
  }

  clear() {
    delete this.input.dataset.isoValue;
    this.input.value = "";
  }

  static formatIsoDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
    if (!match) return "";
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
  }

  format(date) {
    const pad = (number) => String(number).padStart(2, "0");
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  position() {
    if (!this.popover || this.popover.hidden) return;
    const rect = this.input.getBoundingClientRect();
    const width = this.popover.offsetWidth || 296;
    this.popover.style.top = `${Math.min(window.innerHeight - 12, rect.bottom + 8)}px`;
    this.popover.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
  }

  render() {
    const monthStart = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), 1);
    const daysInMonth = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 0).getDate();
    const firstDay = monthStart.getDay();
    const selected = this.getValue();
    const today = this.format(new Date());
    const monthNames = Array.from({ length: 12 }, (_, month) =>
      new Date(2024, month, 1).toLocaleDateString(undefined, { month: "long" }),
    );
    const currentYear = new Date().getFullYear();
    const lastYear = Math.max(currentYear + 1, this.viewDate.getFullYear() + 1);
    const years = Array.from({ length: lastYear - 1999 }, (_, index) => 2000 + index);
    const weekdayLabels = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    let days = "";
    for (let blank = 0; blank < firstDay; blank += 1) days += '<span class="calendar-day calendar-day--blank"></span>';
    for (let day = 1; day <= daysInMonth; day += 1) {
      const date = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth(), day);
      const value = this.format(date);
      const classes = ["calendar-day", value === selected ? "is-selected" : "", value === today ? "is-today" : ""].filter(Boolean).join(" ");
      days += `<button type="button" class="${classes}" data-calendar-date="${value}">${day}</button>`;
    }
    this.popover.innerHTML = `
      <div class="calendar-popover-header">
        <select class="calendar-month-select" data-calendar-month aria-label="Month">
          ${monthNames.map((name, month) => `<option value="${month}" ${month === this.viewDate.getMonth() ? "selected" : ""}>${name}</option>`).join("")}
        </select>
        <select class="calendar-year-select" data-calendar-year aria-label="Year">
          ${years.map((year) => `<option value="${year}" ${year === this.viewDate.getFullYear() ? "selected" : ""}>${year}</option>`).join("")}
        </select>
      </div>
      <div class="calendar-weekdays">${weekdayLabels.map((day) => `<span>${day}</span>`).join("")}</div>
      <div class="calendar-days">${days}</div>
      <button type="button" class="calendar-today" data-calendar-today>Today</button>`;
    this.popover.querySelector("[data-calendar-month]")?.addEventListener("change", (event) => {
      this.viewDate = new Date(this.viewDate.getFullYear(), Number(event.target.value), 1);
      this.render();
      this.position();
    });
    this.popover.querySelector("[data-calendar-year]")?.addEventListener("change", (event) => {
      this.viewDate = new Date(Number(event.target.value), this.viewDate.getMonth(), 1);
      this.render();
      this.position();
    });
    this.popover.querySelectorAll("[data-calendar-date]").forEach((button) => button.addEventListener("click", () => {
      this.setValue(button.dataset.calendarDate);
      this.input.dispatchEvent(new Event("change", { bubbles: true }));
      this.close();
    }));
    this.popover.querySelector("[data-calendar-today]")?.addEventListener("click", () => {
      this.setValue(this.format(new Date()));
      this.input.dispatchEvent(new Event("change", { bubbles: true }));
      this.close();
    });
  }
}

window.PetitstatsCalendarPicker = PetitstatsCalendarPicker;
