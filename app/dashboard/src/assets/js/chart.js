/**
 * Petitstats time-series chart, drawn by Apache ECharts.
 * Renders time-series data as a line/area chart or vertical bars.
 * Features:
 * - Smooth curves with a gradient fill for all active series
 * - Cohesive shared Y-axis scaling
 * - Multi-series synchronized crosshair and anchor dots
 * - Rich unified hover tooltip with color badges (our own HTML, so it can be pinned and clicked)
 * - Toggleable series visibility
 */

const CHART_FONT = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

class PetitstatsChart {
    constructor(containerId, tooltipId, options = {}) {
        this.container = document.getElementById(containerId);
        this.tooltip = document.getElementById(tooltipId);
        if (!this.container || !window.echarts) return;

        this.chart = echarts.init(this.container, null, { renderer: 'svg' });
        this.data = [];
        this.hoveredIndex = -1;
        this.lockedIndex = null;
        this.chartType = options.chartType === 'bar' ? 'bar' : 'line';
        this.onPointClick = typeof options.onPointClick === 'function'
            ? options.onPointClick
            : null;
        this.onTooltipMetricClick = typeof options.onTooltipMetricClick === 'function'
            ? options.onTooltipMetricClick
            : null;
        this.sortTooltipMetrics = options.sortTooltipMetrics === true;
        this.hideZeroTooltipMetrics = options.hideZeroTooltipMetrics === true;
        this.lockTooltipOnClick = options.lockTooltipOnClick === true;
        this.highlightAnomalies = options.highlightAnomalies === true;
        // Lighter look for small multi-line charts: dashed grid, no dot on the last point.
        this.gridLines = options.gridLines || 3;
        this.gridDash = options.gridDash || [];
        this.markLastPoint = options.markLastPoint !== false;
        // The Y axis goes at least this high, so that a handful of events does not fill the chart.
        this.minScale = options.minScale || 8;

        const defaultSeriesConfig = {
            pageviews: {
                key: 'pageviews',
                label: 'Views',
                singular: 'view',
                color: '#60a5fa', // Light blue
                gradientStart: 'rgba(147, 197, 253, 0.5)',
                gradientEnd: 'rgba(191, 219, 254, 0.06)',
                lineWidth: 2.5
            },
            visitors: {
                key: 'visitors',
                label: 'Visitors',
                singular: 'visitor',
                color: '#a78bfa', // Light violet
                gradientStart: 'rgba(196, 181, 253, 0.42)',
                gradientEnd: 'rgba(221, 214, 254, 0.05)',
                lineWidth: 2.25
            },
            sessions: {
                key: 'sessions',
                label: 'Visits',
                singular: 'visit',
                color: '#06b6d4', // Cyan
                gradientStart: 'rgba(6, 182, 212, 0.10)',
                gradientEnd: 'rgba(6, 182, 212, 0.00)',
                lineWidth: 2.0
            },
            events: {
                key: 'events',
                label: 'Events',
                singular: 'event',
                color: '#f59e0b', // Amber / Orange
                gradientStart: 'rgba(245, 158, 11, 0.20)',
                gradientEnd: 'rgba(245, 158, 11, 0.00)',
                lineWidth: 2.25
            }
        };

        this.seriesConfig = Object.assign({}, defaultSeriesConfig, options.seriesConfig || {});
        const initialSeries = options.activeSeries || (options.seriesConfig ? Object.keys(options.seriesConfig) : ['pageviews', 'visitors', 'sessions']);
        this.activeSeries = new Set(initialSeries.filter(k => this.seriesConfig[k]));

        if (this.tooltip && this.onTooltipMetricClick) {
            this.tooltip.classList.add('is-interactive');
            this.tooltip.addEventListener('mouseenter', () => clearTimeout(this.tooltipHideTimer));
            this.tooltip.addEventListener('mouseleave', () => this.hideTooltip());
        }

        this.initEvents();
        // Pages start hidden: the chart gets its real size once its tab is shown.
        new ResizeObserver(() => this.resize()).observe(this.container);
    }

    setData(data, activeSeries = null) {
        this.data = data || [];
        if (activeSeries) {
            this.activeSeries = new Set(Array.isArray(activeSeries) ? activeSeries : [activeSeries]);
        }
        this.hoveredIndex = -1;
        this.lockedIndex = null;
        if (this.tooltip) {
            this.tooltip.classList.remove('visible');
        }
        this.draw();
    }

    toggleSeries(seriesKey) {
        if (!this.seriesConfig[seriesKey]) return;

        // If it is the only active one, don't uncheck (keep at least 1)
        if (this.activeSeries.has(seriesKey) && this.activeSeries.size === 1) {
            return;
        }

        if (this.activeSeries.has(seriesKey)) {
            this.activeSeries.delete(seriesKey);
        } else {
            this.activeSeries.add(seriesKey);
        }

        this.hoveredIndex = -1;
        if (this.tooltip) {
            this.tooltip.classList.remove('visible');
        }
        this.draw();
    }

    isSeriesActive(seriesKey) {
        return this.activeSeries.has(seriesKey);
    }

    setMetric(metric) {
        // Fallback for single metric selection or all
        if (metric === 'all') {
            this.activeSeries = new Set(['pageviews', 'visitors', 'sessions']);
        } else if (this.seriesConfig[metric]) {
            this.activeSeries = new Set([metric]);
        }
        this.draw();
    }

    setChartType(chartType) {
        if (!['line', 'bar'].includes(chartType)) return;
        this.chartType = chartType;
        this.hoveredIndex = -1;
        if (this.tooltip) this.tooltip.classList.remove('visible');
        this.draw();
    }

    resize() {
        if (!this.chart) return;
        this.chart.resize();
    }

    initEvents() {
        // The built-in tooltip only drives the crosshair: ours is HTML so it can be pinned and clicked.
        this.chart.on('updateAxisPointer', (event) => {
            if (this.lockedIndex !== null) return;
            const index = event.axesInfo?.[0]?.value;
            if (!Number.isInteger(index) || !this.data[index]) return;
            clearTimeout(this.tooltipHideTimer);
            this.hoveredIndex = index;
            this.updateTooltip(index);
        });

        const zr = this.chart.getZr();
        zr.on('click', (e) => {
            if (!this.data?.length) return;
            const index = this.getIndexAtPixel(e.offsetX);
            const item = this.data[index];
            if (!item) return;
            if (this.lockTooltipOnClick) this.lockTooltip(index);
            else if (this.onPointClick) this.onPointClick(item, index);
        });

        zr.on('globalout', () => {
            if (this.lockedIndex !== null) return;
            if (this.onTooltipMetricClick) {
                clearTimeout(this.tooltipHideTimer);
                this.tooltipHideTimer = setTimeout(() => this.hideTooltip(), 120);
            } else {
                this.hideTooltip();
            }
        });
    }

    hideTooltip() {
        if (this.lockedIndex !== null) return;
        this.hoveredIndex = -1;
        if (this.tooltip) this.tooltip.classList.remove('visible');
        this.chart?.dispatchAction({ type: 'hideTip' });
    }

    lockTooltip(index) {
        if (!this.data[index]) return;
        clearTimeout(this.tooltipHideTimer);
        this.lockedIndex = index;
        this.hoveredIndex = index;
        // Keep the crosshair on the pinned point while the mouse moves elsewhere.
        this.chart.setOption({ tooltip: { triggerOn: 'none' } });
        this.chart.dispatchAction({ type: 'showTip', seriesIndex: 0, dataIndex: index });
        this.updateTooltip(index);
    }

    unlockTooltip() {
        this.lockedIndex = null;
        this.hoveredIndex = -1;
        if (this.tooltip) this.tooltip.classList.remove('visible');
        this.chart.setOption({ tooltip: { triggerOn: 'mousemove|click' } });
        this.chart.dispatchAction({ type: 'hideTip' });
    }

    isAnomalousPoint(seriesKey, value, index) {
        if (!this.highlightAnomalies || value < 5) return false;
        // Compare against the preceding week, including zero days. This makes
        // a jump such as 0 → 80 visible even for a frequently used event.
        const values = this.data.slice(Math.max(0, index - 7), index)
            .map((point) => Number(point[seriesKey]) || 0)
            .sort((left, right) => left - right);
        if (values.length < 4) return false;
        const middle = Math.floor(values.length / 2);
        const median = values.length % 2 ? values[middle] : (values[middle - 1] + values[middle]) / 2;
        if (median === 0) return value >= 5;
        return value >= median * 3;
    }

    /** The nearest point to a horizontal position, between the first and last points' positions. */
    getIndexAtPixel(x) {
        const last = this.data.length - 1;
        if (last < 1) return 0;
        const first = this.chart.convertToPixel({ xAxisIndex: 0 }, 0);
        const end = this.chart.convertToPixel({ xAxisIndex: 0 }, last);
        const index = Math.round(((x - first) / (end - first)) * last);
        return Math.max(0, Math.min(last, index));
    }

    /** Series to draw, background first: visitors are a subset of views, so they go in front. */
    renderOrder() {
        const preferredOrder = ['pageviews', 'events', 'sessions', 'visitors'];
        const order = preferredOrder.filter(k => this.activeSeries.has(k) && this.seriesConfig[k]);
        Object.keys(this.seriesConfig).forEach(k => {
            if (this.activeSeries.has(k) && !order.includes(k)) order.push(k);
        });
        return order;
    }

    /** About `gridLines` steps of 1, 2, 2.5 or 5 × 10ⁿ, so that grid lines fall on round counts. */
    scale() {
        const values = [];
        this.activeSeries.forEach(k => {
            this.data.forEach(d => values.push(Number(d[k]) || 0));
        });
        const maxVal = Math.max(...values, this.minScale);
        const raw = maxVal / this.gridLines;
        const magnitude = 10 ** Math.floor(Math.log10(raw));
        // Counts are whole numbers: no 2.5 step below 10.
        const factors = magnitude >= 10 ? [1, 2, 2.5, 5, 10] : [1, 2, 5, 10];
        const step = Math.max(1, factors.find(f => f * magnitude >= raw) * magnitude);
        return { max: Math.ceil(maxVal / step) * step, step };
    }

    updateTooltip(index) {
        if (!this.tooltip || index < 0 || !this.data[index]) return;
        const item = this.data[index];
        const dateStr = item.full_label || item.label || '';

        // Build list of metrics for all active series
        const seriesKeys = Object.keys(this.seriesConfig).filter(k => this.activeSeries.has(k));
        if (this.sortTooltipMetrics) {
            seriesKeys.sort((left, right) => {
                const difference = (Number(item[right]) || 0) - (Number(item[left]) || 0);
                return difference || this.seriesConfig[left].label.localeCompare(this.seriesConfig[right].label);
            });
        }

        const visibleSeriesKeys = this.hideZeroTooltipMetrics
            ? seriesKeys.filter(key => (Number(item[key]) || 0) > 0)
            : seriesKeys;
        const metricsHtml = visibleSeriesKeys.map(key => {
            const cfg = this.seriesConfig[key];
            const val = Number(item[key]) || 0;
            const tag = this.onTooltipMetricClick ? 'button' : 'div';
            const isAnomaly = this.isAnomalousPoint(key, val, index);
            return `
                <${tag} class="chart-tooltip-metric${this.onTooltipMetricClick ? ' is-actionable' : ''}" ${this.onTooltipMetricClick ? `data-tooltip-series="${key}" type="button"` : ''}>
                    <span class="chart-tooltip-dot" style="background: ${cfg.color};"></span>
                    <span class="chart-tooltip-name">${cfg.label}</span>
                    ${isAnomaly ? '<span class="chart-tooltip-anomaly" title="Unusual peak: at least 3× the median for this event">!</span>' : ''}
                    <span class="chart-tooltip-val">${cfg.format ? cfg.format(val) : val.toLocaleString()}</span>
                </${tag}>
            `;
        }).join('');
        const isLocked = this.lockedIndex === index;

        this.tooltip.innerHTML = `
            <div class="chart-tooltip-date">${dateStr}${isLocked ? '<span class="chart-tooltip-pinned">Pinned</span>' : ''}</div>
            <div class="chart-tooltip-metrics-list">${metricsHtml}</div>
            ${isLocked && this.onPointClick ? '<div class="chart-tooltip-actions"><button type="button" data-tooltip-action="sessions">View sessions for this day</button><button type="button" class="chart-tooltip-unpin" data-tooltip-action="unpin" aria-label="Unpin tooltip">×</button></div>' : (this.onTooltipMetricClick ? '<div class="chart-tooltip-hint">Click the graph to pin this list, then select an event</div>' : (this.onPointClick ? '<div class="chart-tooltip-hint">Click to view sessions</div>' : ''))}
        `;
        if (this.onTooltipMetricClick) {
            this.tooltip.querySelectorAll('[data-tooltip-series]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    this.onTooltipMetricClick(item, button.dataset.tooltipSeries, index);
                });
            });
        }
        this.tooltip.querySelector('[data-tooltip-action="sessions"]')?.addEventListener('click', () => this.onPointClick(item, index));
        this.tooltip.querySelector('[data-tooltip-action="unpin"]')?.addEventListener('click', () => this.unlockTooltip());

        // Place it beside the highest point among active series at this index.
        const topValAtIndex = Math.max(...seriesKeys.map(k => Number(item[k]) || 0), 0);
        const [pointX, pointY] = this.chart.convertToPixel({ gridIndex: 0 }, [index, topValAtIndex]);
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;

        // Keep the tooltip beside the selected point. The left-side fallback
        // prevents it from being clipped for the final points in the chart.
        const gap = 14;
        const tooltipWidth = this.tooltip.offsetWidth;
        const tooltipHeight = this.tooltip.offsetHeight;
        const canOpenRight = pointX + gap + tooltipWidth <= width - 4;
        const left = canOpenRight
            ? pointX + gap
            : Math.max(4, pointX - gap - tooltipWidth);
        const top = Math.max(4, Math.min(pointY - tooltipHeight / 2, height - tooltipHeight - 4));
        this.tooltip.style.left = `${left}px`;
        this.tooltip.style.top = `${top}px`;
        this.tooltip.style.transform = 'none';

        this.tooltip.classList.add('visible');
    }

    lineSeries(key, z) {
        const cfg = this.seriesConfig[key];
        const values = this.data.map(item => Number(item[key]) || 0);
        const last = values.length - 1;
        const dot = { color: '#ffffff', borderColor: cfg.color, borderWidth: 2.5 };
        return {
            name: cfg.label,
            type: 'line',
            z,
            data: values,
            smooth: 0.3,
            // Stay above the floor between two points, as counts never go below zero.
            smoothMonotone: 'x',
            showSymbol: false,
            symbol: 'circle',
            symbolSize: 9,
            itemStyle: dot,
            lineStyle: { color: cfg.color, width: cfg.lineWidth, cap: 'round', join: 'round' },
            areaStyle: {
                color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: cfg.gradientStart },
                    { offset: 0.7, color: cfg.gradientEnd },
                    { offset: 1, color: 'rgba(255, 255, 255, 0)' },
                ]),
            },
            emphasis: { disabled: true },
            markPoint: this.markLastPoint && values.length > 3 ? {
                silent: true,
                symbol: 'circle',
                symbolSize: 9,
                itemStyle: dot,
                label: { show: false },
                animation: false,
                data: [{ coord: [last, values[last]] }],
            } : undefined,
        };
    }

    barSeries(key, z) {
        const cfg = this.seriesConfig[key];
        return {
            name: cfg.label,
            type: 'bar',
            z,
            data: this.data.map(item => Number(item[key]) || 0),
            // Metrics share a single slot, drawn on top of each other.
            barGap: '-100%',
            barCategoryGap: '28%',
            barMaxWidth: 96,
            itemStyle: { color: cfg.color, borderRadius: [4, 4, 0, 0] },
            emphasis: { disabled: true },
        };
    }

    draw() {
        if (!this.chart) return;
        const isBar = this.chartType === 'bar';
        const isEmpty = !this.data || this.data.length === 0;
        const { max, step } = this.scale();
        const axisText = { color: '#94a3b8', fontSize: 11, fontFamily: CHART_FONT };

        this.chart.setOption({
            animationDuration: 500,
            animationDurationUpdate: 300,
            textStyle: { fontFamily: CHART_FONT },
            grid: { left: 8, right: 16, top: 16, bottom: 4, containLabel: true },
            title: {
                show: isEmpty,
                text: 'No data available for this period',
                left: 'center',
                top: 'middle',
                textStyle: { color: '#94a3b8', fontSize: 13, fontWeight: 'normal', fontFamily: CHART_FONT },
            },
            tooltip: {
                show: !isEmpty,
                trigger: 'axis',
                showContent: false,
                triggerOn: this.lockedIndex === null ? 'mousemove|click' : 'none',
                axisPointer: isBar
                    ? { type: 'shadow', shadowStyle: { color: 'rgba(148, 163, 184, 0.12)' } }
                    : { type: 'line', lineStyle: { color: '#94a3b8', width: 1, type: [3, 3] } },
            },
            xAxis: {
                type: 'category',
                data: this.data.map(item => item.label || ''),
                boundaryGap: isBar,
                show: !isEmpty,
                axisLine: { lineStyle: { color: '#e2e8f0' } },
                axisTick: { show: false },
                axisLabel: { ...axisText, margin: 10, hideOverlap: true },
            },
            yAxis: {
                type: 'value',
                min: 0,
                max,
                interval: step,
                show: !isEmpty,
                axisLabel: { ...axisText, formatter: (value) => Math.round(value).toLocaleString() },
                splitLine: {
                    lineStyle: {
                        color: this.gridDash.length ? '#e2e8f0' : '#f1f5f9',
                        type: this.gridDash.length ? this.gridDash : 'solid',
                    },
                },
            },
            series: isEmpty ? [] : this.renderOrder().map((key, i) => (isBar ? this.barSeries(key, i + 2) : this.lineSeries(key, i + 2))),
        }, { notMerge: true });
    }

}

window.PetitstatsChart = PetitstatsChart;
