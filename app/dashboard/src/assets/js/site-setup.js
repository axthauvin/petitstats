/**
 * Add a website: start from scratch, or import the history of another analytics service
 * into a new or existing website. One window, with the steps of the chosen path.
 */

// Upload wording and accepted files for each available importer.
const IMPORT_SOURCES = {
    umami: {
        label: 'Your Umami export',
        help: 'In Umami, open the website settings and export its data as a ZIP file.',
        hint: '.zip archive, or the website_event.csv file',
        accept: '.zip,.csv',
        docs: 'umami/',
    },
    matomo: {
        label: 'Your Matomo export',
        help: 'In Matomo, open the Visits Log for the whole period and export all rows as CSV or JSON.',
        hint: '.csv or .json file, or a .zip of several exports',
        accept: '.csv,.json,.zip',
        docs: 'matomo/',
    },
};

// Steps shown in the stepper for each path. "start" and "working" are not listed.
const SETUP_STEPS = {
    new: [['website', 'Website'], ['done', 'Tracking tag']],
    import: [['source', 'Export'], ['website', 'Website'], ['history', 'History'], ['done', 'Done']],
};

const DAY_MS = 86400000;

const SiteSetup = {
    modal: null,
    state: null,

    init() {
        this.modal = document.getElementById('siteSetupModal');
        if (!this.modal) return;
        this.el = (id) => document.getElementById(id);
        this.bind();
    },

    /** Opens the window. mode is "start" (choose a path), "new" or "import". */
    open(mode = 'start') {
        if (!this.modal) return;
        this.state = {
            entry: mode,
            path: mode === 'import' ? 'import' : 'new',
            step: mode === 'start' ? 'start' : mode === 'import' ? 'source' : 'website',
            provider: null,
            file: null,
            serverPath: '',
            inspection: null,
            inspecting: false,
            target: 'new',
            sites: [],
            autoFilled: { name: '', domain: '' },
            siteIdEdited: false,
            createdSite: null,
            result: null,
            snippet: '',
            snippetOnly: false,
        };
        this.resetFields();
        this.loadSites();
        this.render();
        this.modal.classList.add('active');
        this.focusStep();
    },

    /** Shows the tracking tag of an existing website. */
    showSnippet(siteId, siteName, snippet) {
        this.open('new');
        Object.assign(this.state, { step: 'done', snippetOnly: true, snippet, createdSite: { id: siteId, name: siteName || siteId } });
        this.render();
    },

    close() {
        this.modal?.classList.remove('active');
    },

    bind() {
        this.modal.querySelectorAll('[data-setup-path]').forEach((button) => {
            button.addEventListener('click', () => {
                this.state.path = button.dataset.setupPath;
                this.go(this.state.path === 'import' ? 'source' : 'website');
            });
        });

        this.modal.querySelectorAll('#ssProviders .provider-card:not(.disabled)').forEach((card) => {
            card.addEventListener('click', () => this.selectProvider(card.dataset.provider));
        });

        const dropzone = this.el('ssDropzone');
        const fileInput = this.el('ssFileInput');
        dropzone.addEventListener('click', () => fileInput.click());
        dropzone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files?.[0]) this.selectFile(fileInput.files[0]);
        });
        ['dragenter', 'dragover'].forEach((type) => dropzone.addEventListener(type, (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        }));
        ['dragleave', 'drop'].forEach((type) => dropzone.addEventListener(type, (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        }));
        dropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer?.files?.[0]) this.selectFile(e.dataTransfer.files[0]);
        });
        this.el('ssFileRemove').addEventListener('click', () => {
            this.el('ssServerPath').value = '';
            this.clearSource();
        });

        let pathTimer = null;
        this.el('ssServerPath').addEventListener('input', (e) => {
            clearTimeout(pathTimer);
            const path = e.target.value.trim();
            pathTimer = setTimeout(() => {
                if (path) {
                    this.state.file = null;
                    this.state.serverPath = path;
                    this.inspect();
                } else {
                    this.clearSource();
                }
            }, 500);
        });

        this.el('ssTargetToggle').querySelectorAll('[data-target]').forEach((button) => {
            button.addEventListener('click', () => {
                this.state.target = button.dataset.target;
                this.render();
            });
        });

        const nameInput = this.el('ssSiteName');
        const idInput = this.el('ssSiteId');
        nameInput.addEventListener('input', () => {
            if (!this.state.siteIdEdited) idInput.value = this.slug(nameInput.value);
            this.el('ssSiteIdPreview').textContent = idInput.value;
        });
        idInput.addEventListener('input', () => {
            this.state.siteIdEdited = true;
            this.el('ssSiteIdPreview').textContent = idInput.value;
        });
        [nameInput, this.el('ssSiteDomain'), idInput].forEach((input) => {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.next();
                }
            });
        });
        this.el('ssExistingSite').addEventListener('change', () => this.render());

        this.el('ssBack').addEventListener('click', () => this.back());
        this.el('ssNext').addEventListener('click', () => this.next());
    },

    resetFields() {
        ['ssSiteName', 'ssSiteDomain', 'ssSiteId', 'ssServerPath'].forEach((id) => { this.el(id).value = ''; });
        this.el('ssSiteIdPreview').textContent = '';
        this.el('ssFileInput').value = '';
        this.modal.querySelectorAll('.setup-advanced').forEach((details) => { details.open = false; });
        const extend = this.modal.querySelector('input[name="ssRetention"][value="extend"]');
        if (extend) extend.checked = true;
    },

    async loadSites() {
        try {
            const data = await Api.getSites();
            this.state.sites = data.sites || [];
        } catch (err) {
            this.state.sites = [];
        }
        const select = this.el('ssExistingSite');
        select.innerHTML = '';
        this.state.sites.forEach((site) => {
            const option = document.createElement('option');
            option.value = site.id;
            option.textContent = site.domain ? `${site.name || site.id} (${site.domain})` : (site.name || site.id);
            select.appendChild(option);
        });
        this.render();
    },

    /* ---------- Navigation ---------- */

    steps() {
        return SETUP_STEPS[this.state.path].filter(([step]) => step !== 'history' || this.needsHistory());
    },

    go(step) {
        this.state.step = step;
        this.hideError();
        this.render();
        this.focusStep();
    },

    back() {
        const s = this.state;
        if (s.step === 'done') return this.close();
        const order = this.steps().map(([step]) => step);
        const index = order.indexOf(s.step);
        if (index > 0) return this.go(order[index - 1]);
        if (s.entry === 'start') return this.go('start');
    },

    async next() {
        const s = this.state;
        if (s.step === 'source') {
            if (s.inspection) this.go('website');
            return;
        }
        if (s.step === 'website') {
            if (!this.validateWebsite()) return;
            if (s.path === 'new') return this.createOnly();
            return this.needsHistory() ? this.go('history') : this.runImport();
        }
        if (s.step === 'history') return this.runImport();
        if (s.step === 'done') {
            this.close();
            const siteId = s.result?.site_id || s.createdSite?.id;
            if (siteId && window.App) window.App.selectSiteAndOpen(siteId);
        }
    },

    /* ---------- Import source ---------- */

    selectProvider(provider) {
        if (this.state.provider === provider) return;
        this.state.provider = provider;
        this.clearSource();
    },

    selectFile(file) {
        this.state.file = file;
        this.state.serverPath = '';
        this.el('ssServerPath').value = '';
        this.inspect();
    },

    clearSource() {
        Object.assign(this.state, { file: null, serverPath: '', inspection: null, inspecting: false, inspectError: '' });
        this.el('ssFileInput').value = '';
        this.render();
    },

    async inspect() {
        const s = this.state;
        const token = {};
        s.inspectToken = token;
        Object.assign(s, { inspection: null, inspecting: true, inspectError: '' });
        this.hideError();
        this.render();

        const form = new FormData();
        form.append('provider', s.provider);
        if (s.file) form.append('file', s.file);
        else form.append('server_path', s.serverPath);

        try {
            const res = await Api.inspectImport(form);
            if (s.inspectToken !== token) return;
            s.inspection = res;
            this.prefillFromExport(res);
        } catch (err) {
            if (s.inspectToken !== token) return;
            s.inspectError = err.message || 'This file could not be read.';
        } finally {
            if (s.inspectToken === token) {
                s.inspecting = false;
                this.render();
            }
        }
    },

    prefillFromExport(res) {
        const auto = this.state.autoFilled;
        const name = this.el('ssSiteName');
        const domain = this.el('ssSiteDomain');
        if (res.suggested_name && (!name.value || name.value === auto.name)) {
            name.value = res.suggested_name;
            auto.name = res.suggested_name;
            name.dispatchEvent(new Event('input'));
        }
        if (res.detected_host && (!domain.value || domain.value === auto.domain)) {
            domain.value = res.detected_host;
            auto.domain = res.detected_host;
        }
        // Default to the website that already tracks this domain, if any.
        const match = this.state.sites.find((site) => this.host(site.domain) === this.host(res.detected_host || ''));
        if (match) {
            this.state.target = 'existing';
            this.el('ssExistingSite').value = match.id;
        }
    },

    /* ---------- Website ---------- */

    validateWebsite() {
        const s = this.state;
        if (s.path === 'import' && s.target === 'existing') {
            if (!this.el('ssExistingSite').value) return this.showError('Choose the website that receives the data.');
            return true;
        }
        if (s.createdSite) return true;
        const name = this.el('ssSiteName').value.trim();
        const domain = this.host(this.el('ssSiteDomain').value);
        const id = this.el('ssSiteId').value.trim() || this.slug(name);
        if (!name) return this.showError('Give your website a name.', 'ssSiteName');
        if (!domain) return this.showError('Enter the domain of your website, for example example.com.', 'ssSiteDomain');
        if (!id) return this.showError('The website identifier can only use letters, numbers, dashes and underscores.', 'ssSiteId');
        if (s.sites.some((site) => site.id === id)) {
            return this.showError(`A website already uses the identifier “${id}”. Choose another one under Website identifier.`, 'ssSiteId');
        }
        this.el('ssSiteDomain').value = domain;
        this.el('ssSiteId').value = id;
        return true;
    },

    /** The website the import goes to: its name and how many days it keeps. */
    destination() {
        const s = this.state;
        if (s.target === 'existing' && s.path === 'import') {
            const site = s.sites.find((item) => item.id === this.el('ssExistingSite').value);
            return { name: site?.name || site?.id || 'This website', retention: Number(site?.retention_days) || this.defaultRetention(), existing: true };
        }
        return { name: this.el('ssSiteName').value.trim() || 'Your new website', retention: this.defaultRetention(), existing: false };
    },

    async createSite() {
        const s = this.state;
        if (s.createdSite) return s.createdSite;
        const res = await Api.createSite({
            name: this.el('ssSiteName').value.trim(),
            id: this.el('ssSiteId').value.trim(),
            domain: this.el('ssSiteDomain').value.trim(),
        });
        s.createdSite = res.site;
        s.snippet = res.snippet;
        await this.refreshSites(res.site.id);
        return res.site;
    },

    async createOnly() {
        this.go('working');
        this.el('ssWorkingTitle').textContent = 'Creating your website';
        try {
            await this.createSite();
            this.go('done');
        } catch (err) {
            this.go('website');
            this.showError(err.message || 'The website could not be created.');
        }
    },

    /* ---------- History kept ---------- */

    defaultRetention() {
        return Number(this.state.inspection?.default_retention_days) || 395;
    },

    maxRetention() {
        return Number(this.state.inspection?.max_retention_days) || 760;
    },

    exportStart() {
        const start = this.state.inspection?.date_start;
        return start ? new Date(`${start.replace(' ', 'T')}Z`) : null;
    },

    /** True when the export has visits older than the destination keeps. */
    needsHistory() {
        const s = this.state;
        if (s.path !== 'import' || !s.inspection) return false;
        const start = this.exportStart();
        return Boolean(start) && start.getTime() < Date.now() - this.destination().retention * DAY_MS;
    },

    /** Days needed to keep the whole export, with a month to spare and capped at the maximum. */
    extendedRetention() {
        const needed = Math.ceil((Date.now() - this.exportStart().getTime()) / DAY_MS) + 1;
        return { days: Math.min(Math.ceil(needed / 30) * 30 + 30, this.maxRetention()), capped: needed > this.maxRetention() };
    },

    renderHistory() {
        if (!this.needsHistory()) return;
        const dest = this.destination();
        const cutoff = new Date(Date.now() - dest.retention * DAY_MS);
        const keeps = dest.existing ? `${dest.name} keeps` : 'New websites keep';
        this.el('ssHistoryIntro').textContent = `Your export starts on ${this.formatDate(this.exportStart())}. ${keeps} ${dest.retention} days of visits, so visits from before ${this.formatDate(cutoff)} would be deleted.`;

        const extended = this.extendedRetention();
        this.el('ssExtendTitle').textContent = `Keep ${extended.days} days of visits`;
        this.el('ssExtendDesc').textContent = extended.capped
            ? `The longest Petitstats allows. Visits from before ${this.formatDate(new Date(Date.now() - extended.days * DAY_MS))} are still left out.`
            : 'Your whole history is imported. The oldest visits are deleted as they pass this age.';
        this.el('ssKeepTitle').textContent = `Keep ${dest.retention} days`;
        this.el('ssKeepDesc').textContent = `Visits from before ${this.formatDate(cutoff)} are not imported.`;
    },

    /* ---------- Import ---------- */

    async runImport() {
        const s = this.state;
        const dest = this.destination();
        this.go('working');
        this.el('ssWorkingTitle').textContent = `Importing into ${dest.name}`;
        try {
            const site = s.target === 'existing' ? { id: this.el('ssExistingSite').value } : await this.createSite();
            const form = new FormData();
            form.append('provider', s.provider);
            form.append('site_id', site.id);
            if (s.file) form.append('file', s.file);
            else form.append('server_path', s.serverPath);
            const choice = this.modal.querySelector('input[name="ssRetention"]:checked')?.value;
            if (this.needsHistory() && choice === 'extend') {
                form.append('retention_days', String(this.extendedRetention().days));
            }

            const result = await Api.submitImport(form);
            if (!result.success) throw new Error(result.error || 'The import failed.');
            s.result = result;
            await this.refreshSites(result.site_id);
            this.go('done');
        } catch (err) {
            this.go(this.needsHistory() ? 'history' : 'website');
            this.showError(err.message || 'The import failed.');
        }
    },

    async refreshSites(siteId) {
        try {
            const data = await Api.getSites();
            this.state.sites = data.sites || [];
            if (window.App) {
                window.App.currentSiteId = siteId;
                window.App.updateSiteSelect(this.state.sites);
            }
            if (window.WebsitesPage && window.App?.currentPage === 'websites') window.WebsitesPage.load();
        } catch (err) {
            // The list refreshes on the next page load anyway.
        }
    },

    /* ---------- Rendering ---------- */

    render() {
        const s = this.state;
        if (!s) return;

        this.modal.querySelectorAll('[data-setup-step]').forEach((section) => {
            section.hidden = section.dataset.setupStep !== s.step;
        });

        this.renderHeader();
        this.renderStepper();
        this.renderSource();
        this.renderWebsite();
        this.renderHistory();
        this.renderDone();
        this.renderFooter();
    },

    renderHeader() {
        const s = this.state;
        const title = s.snippetOnly ? 'Tracking tag'
            : s.step === 'start' ? 'Add a website'
            : s.path === 'import' ? 'Import past data' : 'Add a website';
        const subtitle = s.snippetOnly ? 'Add this tag to your pages to collect visits.'
            : s.step === 'start' ? 'Start collecting visits, or bring your history from another analytics service.'
            : s.path === 'import' ? 'Bring your history from another analytics service into Petitstats.'
            : 'Create the website, then add its tracking tag to your pages.';
        this.el('siteSetupTitle').textContent = title;
        this.el('siteSetupSubtitle').textContent = subtitle;
    },

    renderStepper() {
        const s = this.state;
        const stepper = this.el('siteSetupStepper');
        const steps = this.steps();
        const current = s.step === 'working' ? 'done' : s.step;
        const index = steps.findIndex(([step]) => step === current);
        stepper.hidden = s.step === 'start' || s.snippetOnly;
        stepper.innerHTML = '';
        steps.forEach(([, label], i) => {
            const item = document.createElement('li');
            item.textContent = label;
            if (i < index || (s.step === 'done' && i === index)) item.className = 'complete';
            if (i === index && s.step !== 'done') item.className = 'active';
            if (i === index) item.setAttribute('aria-current', 'step');
            stepper.appendChild(item);
        });
    },

    renderSource() {
        const s = this.state;
        this.modal.querySelectorAll('#ssProviders .provider-card[data-provider]').forEach((card) => {
            const selected = card.dataset.provider === s.provider;
            card.classList.toggle('selected', selected);
            card.setAttribute('aria-pressed', String(selected));
        });
        const source = IMPORT_SOURCES[s.provider];
        this.el('ssUpload').hidden = !source;
        if (!source) return;

        // The upload title repeats the logo of the chosen service.
        const label = this.el('ssUploadLabel');
        const card = this.modal.querySelector(`#ssProviders [data-provider="${s.provider}"]`);
        label.replaceChildren(...(card ? [card.querySelector('.provider-logo').cloneNode(true)] : []), source.label);
        label.style.setProperty('--brand', card?.style.getPropertyValue('--brand') || 'currentColor');
        this.el('ssUploadHelp').textContent = source.help;
        // The guide of the chosen service, under the importing page of the docs website.
        const docs = this.el('ssUploadDocs');
        docs.href = docs.dataset.base + source.docs;
        this.el('ssDropzoneHint').textContent = source.hint;
        this.el('ssFileInput').accept = source.accept;

        const hasSource = Boolean(s.file || s.serverPath);
        this.el('ssDropzone').hidden = hasSource;
        this.el('ssFile').hidden = !hasSource;
        this.el('ssFileName').textContent = s.file ? `${s.file.name} · ${this.formatSize(s.file.size)}` : s.serverPath;

        const status = this.el('ssFileStatus');
        status.className = 'setup-file-status';
        if (s.inspecting) {
            status.textContent = 'Reading your export…';
        } else if (s.inspectError) {
            status.textContent = s.inspectError;
            status.classList.add('is-error');
        } else if (s.inspection) {
            status.textContent = 'Ready to import';
            status.classList.add('is-ok');
        } else {
            status.textContent = '';
        }

        const summary = this.el('ssSummary');
        summary.hidden = !s.inspection;
        if (s.inspection) {
            const i = s.inspection;
            this.el('ssSummaryVisits').textContent = this.formatNumber(i.visits ?? 0);
            this.el('ssSummaryPeriod').textContent = this.formatPeriod(i.date_start, i.date_end);
            this.el('ssSummaryHost').textContent = i.detected_host || 'Unknown';
        }
    },

    renderWebsite() {
        const s = this.state;
        const isImport = s.path === 'import';
        const hasSites = s.sites.length > 0;
        if (!isImport || !hasSites) s.target = 'new';

        const toggle = this.el('ssTargetToggle');
        toggle.hidden = !isImport || !hasSites || Boolean(s.createdSite);
        toggle.querySelectorAll('[data-target]').forEach((button) => {
            button.setAttribute('aria-checked', String(button.dataset.target === s.target));
        });

        const existing = isImport && s.target === 'existing';
        this.el('ssNewSiteFields').hidden = existing;
        this.el('ssExistingSiteField').hidden = !existing;
        this.el('ssDetectedHint').hidden = !(isImport && s.inspection && (s.autoFilled.name || s.autoFilled.domain));
        // Once created, the website cannot change: a retried import goes to the same one.
        ['ssSiteName', 'ssSiteDomain', 'ssSiteId'].forEach((id) => { this.el(id).disabled = Boolean(s.createdSite); });
    },

    renderDone() {
        const s = this.state;
        if (s.step !== 'done') return;
        const r = s.result;
        const site = s.createdSite;
        const name = r?.site_name || site?.name || r?.site_id || '';

        if (s.snippetOnly) {
            this.el('ssDoneTitle').textContent = `Tracking tag for ${name}`;
            this.el('ssDoneText').textContent = 'Paste it in the <head> of every page you want to track.';
        } else if (r) {
            const skipped = Number(r.skipped_sessions) || 0;
            const duplicates = Number(r.duplicate_sessions) || 0;
            this.el('ssDoneTitle').textContent = `Your history is in ${name}`;
            this.el('ssDoneText').textContent = (r.sessions || !duplicates
                ? `${this.formatNumber(r.sessions)} visits imported.`
                    + (duplicates ? ` ${this.formatNumber(duplicates)} were already there and were not added again.` : '')
                : `All ${this.formatNumber(duplicates)} visits of this export were already there, nothing was added.`)
                + (skipped ? ` ${this.formatNumber(skipped)} older visits were left out, as chosen.` : '')
                + (site ? ' Add the tag below so new visits keep coming in.' : '');
        } else {
            this.el('ssDoneTitle').textContent = `${name} is ready`;
            this.el('ssDoneText').textContent = 'Add the tag below to your pages. Visits show up in the dashboard within seconds.';
        }

        this.el('ssResults').hidden = !r;
        if (r) {
            this.el('ssResultVisits').textContent = this.formatNumber(r.sessions);
            this.el('ssResultPageviews').textContent = this.formatNumber(r.pageviews);
            this.el('ssResultEvents').textContent = this.formatNumber(r.custom_events);
            this.el('ssResultPeriod').textContent = this.formatPeriod(r.date_start, r.date_end);
        }

        const snippetBox = this.el('createdSiteSnippet');
        this.el('ssSnippetBlock').hidden = !s.snippet;
        if (s.snippet) {
            snippetBox.dataset.rawText = s.snippet;
            if (window.ClipboardHelper?.highlightHtml) snippetBox.innerHTML = window.ClipboardHelper.highlightHtml(s.snippet);
            else snippetBox.textContent = s.snippet;
        }
    },

    renderFooter() {
        const s = this.state;
        const back = this.el('ssBack');
        const next = this.el('ssNext');
        this.el('ssFooter').hidden = s.step === 'start' || s.step === 'working';

        const firstStep = this.steps()[0][0] === s.step;
        back.hidden = s.step !== 'done' && firstStep && s.entry !== 'start';
        back.textContent = s.step === 'done' ? 'Close' : 'Back';
        next.disabled = false;

        if (s.step === 'source') {
            next.textContent = 'Continue';
            next.disabled = !s.inspection;
        } else if (s.step === 'website') {
            next.textContent = s.path === 'new' ? 'Create website' : this.needsHistory() ? 'Continue' : 'Import data';
        } else if (s.step === 'history') {
            next.textContent = 'Import data';
        } else if (s.step === 'done') {
            next.textContent = 'View analytics';
        }
    },

    focusStep() {
        setTimeout(() => {
            const section = this.modal.querySelector(`[data-setup-step="${this.state.step}"]`);
            const target = section?.querySelector('input:not([disabled]):not([type="radio"]), .setup-choice, .provider-card:not(.disabled)');
            target?.focus();
        }, 60);
    },

    showError(message, fieldId = null) {
        const box = this.el('siteSetupError');
        box.textContent = message;
        box.hidden = false;
        if (fieldId) this.el(fieldId)?.focus();
        if (fieldId === 'ssSiteId') this.el('ssSiteId').closest('details').open = true;
        return false;
    },

    hideError() {
        this.el('siteSetupError').hidden = true;
    },

    /* ---------- Formatting ---------- */

    slug(value) {
        return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    },

    /** "https://www.Example.com/path" → "www.example.com" */
    host(value) {
        return String(value || '').trim().toLowerCase().replace(/^[a-z]+:\/\//, '').split(/[/?#]/)[0];
    },

    formatNumber(value) {
        return Number(value || 0).toLocaleString('en');
    },

    formatDate(date) {
        return date.toLocaleDateString('en', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
    },

    formatPeriod(start, end) {
        if (!start || !end) return 'Unknown';
        const toDate = (value) => new Date(`${value.replace(' ', 'T')}Z`);
        return `${this.formatDate(toDate(start))} → ${this.formatDate(toDate(end))}`;
    },

    formatSize(bytes) {
        if (!bytes) return '0 KB';
        const units = ['bytes', 'KB', 'MB', 'GB'];
        const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
        return `${parseFloat((bytes / 1024 ** i).toFixed(1))} ${units[i]}`;
    },
};

window.SiteSetup = SiteSetup;

document.addEventListener('DOMContentLoaded', () => {
    SiteSetup.init();
    ['btnSidebarImport', 'btnWebsitesImport'].forEach((id) => {
        document.getElementById(id)?.addEventListener('click', (e) => {
            e.preventDefault();
            SiteSetup.open('import');
        });
    });
});
