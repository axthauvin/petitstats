const SettingsPage = {
  initialized: false,
  currentUser: null,
  trackingTags: { domains: [], internalIps: [] },
  databaseTested: false,

  init() {
    if (this.initialized) return;
    this.initialized = true;

    const form = document.getElementById('inviteUserForm');
    if (form) form.addEventListener('submit', (event) => this.invite(event));

    const copy = document.getElementById('copyInviteLink');
    if (copy) {
      copy.addEventListener('click', () => {
        const input = document.getElementById('inviteLink');
        if (!input) return;
        if (window.ClipboardHelper && typeof ClipboardHelper.copy === 'function') {
          ClipboardHelper.copy(input.value, copy);
        } else if (navigator.clipboard) {
          navigator.clipboard.writeText(input.value).then(() => {
            const originalText = copy.textContent;
            copy.textContent = 'Copied!';
            setTimeout(() => { copy.textContent = originalText; }, 2000);
          });
        }
      });
    }
    const trackingForm = document.getElementById('trackingSettingsForm');
    if (trackingForm) trackingForm.addEventListener('submit', (event) => this.saveTracking(event));
    const siteSelect = document.getElementById('trackingSiteSelect');
    if (siteSelect) siteSelect.addEventListener('change', () => this.loadTrackingConfig());
    this.setupTrackingTagEditor('domains', 'trackingDomainsInput', 'addTrackingDomain');
    this.setupTrackingTagEditor('internalIps', 'trackingInternalIpsInput', 'addTrackingInternalIp');
    const copySnippet = document.getElementById('copyTrackingSnippet');
    if (copySnippet) copySnippet.addEventListener('click', () => ClipboardHelper.copy(document.getElementById('trackingSnippet').value, copySnippet));
    document.querySelectorAll('[data-settings-tab]').forEach((tab) => tab.addEventListener('click', () => this.selectTab(tab.dataset.settingsTab)));
    const timeFormat = document.getElementById('timeFormatSelect');
    if (timeFormat) {
      timeFormat.value = TimeFormat.get();
      timeFormat.addEventListener('change', () => {
        TimeFormat.set(timeFormat.value);
        const feedback = document.getElementById('timeFormatFeedback');
        if (feedback) { feedback.className = 'settings-feedback settings-success'; feedback.textContent = `Saved. Times now read ${TimeFormat.time(new Date())}.`; }
      });
    }
    const databaseDriver = document.getElementById('databaseDriver');
    if (databaseDriver) databaseDriver.addEventListener('change', () => this.toggleDatabaseFields());
    const databaseForm = document.getElementById('databaseSettingsForm');
    if (databaseForm) databaseForm.addEventListener('submit', (event) => this.saveDatabase(event));
    const testDatabase = document.getElementById('testDatabaseConnection');
    if (testDatabase) testDatabase.addEventListener('click', () => this.testDatabase());
    const saveDatabase = document.getElementById('saveDatabaseConnector');
    if (saveDatabase) saveDatabase.addEventListener('click', () => this.saveDatabase());
    document.querySelectorAll('#databaseSettingsForm input').forEach((input) => input.addEventListener('input', () => this.invalidateDatabaseTest()));
    document.getElementById('settingsAssistantsPanel')?.addEventListener('click', (event) => this.handleAssistantsClick(event));
    document.getElementById('settingsAssistantsPanel')?.addEventListener('change', (event) => { if (event.target.matches('[data-allow-write]')) this.allowWrite(event.target); });
    document.getElementById('updateCheckNow')?.addEventListener('click', () => this.loadVersion(true));
    document.getElementById('updateInstall')?.addEventListener('click', () => this.installUpdate());
    document.getElementById('updateRollback')?.addEventListener('click', () => this.rollbackUpdate());
  },

  async load() {
    this.init();
    this.loadApi();
    this.loadVersion();
    const list = document.getElementById('usersList');
    if (!list) return;

    list.innerHTML = '<p class="settings-muted">Loading users…</p>';

    try {
      const data = await Api.getUsers();
      this.currentUser = data.current_user || null;
      const isAdmin = Boolean(this.currentUser && this.currentUser.role === 'admin');
      await this.loadTrackingSites(isAdmin);
      await this.loadDatabaseConfig(isAdmin);

      // Only administrators can invite new users
      const inviteCard = document.getElementById('inviteUserCard');
      if (inviteCard) {
        inviteCard.hidden = !isAdmin;
      }

      const users = Array.isArray(data.users) ? data.users : [];
      if (users.length === 0) {
        list.innerHTML = '<p class="settings-muted">No authorized users found.</p>';
        return;
      }

      const adminCount = users.filter((u) => u.role === 'admin').length;

      list.innerHTML = users.map((user) => {
        const isSelf = Boolean(this.currentUser && Number(this.currentUser.id) === Number(user.id));
        const isTargetAdmin = user.role === 'admin';
        const isOnlyAdmin = isTargetAdmin && adminCount <= 1;

        let actionsHtml = '';
        if (isAdmin) {
          let roleBtn = '';
          if (isSelf) {
            roleBtn = `
              <button type="button" class="btn-outline btn-sm btn-user-action" disabled title="You cannot change your own administrator status.">
                ${isTargetAdmin ? 'Admin' : 'Member'}
              </button>
            `;
          } else if (isTargetAdmin) {
            if (isOnlyAdmin) {
              roleBtn = `
                <button type="button" class="btn-outline btn-sm btn-user-action" disabled title="Cannot remove the only administrator.">
                  Remove Admin
                </button>
              `;
            } else {
              roleBtn = `
                <button type="button" class="btn-outline btn-sm btn-user-action" onclick="SettingsPage.toggleRole(${user.id}, 'member', '${this.escapeHtml(user.email)}')" title="Revoke administrator privileges">
                  Remove Admin
                </button>
              `;
            }
          } else {
            roleBtn = `
              <button type="button" class="btn-outline btn-sm btn-user-action" onclick="SettingsPage.toggleRole(${user.id}, 'admin', '${this.escapeHtml(user.email)}')" title="Grant administrator privileges">
                Make Admin
              </button>
            `;
          }

          let deleteBtn = '';
          if (isSelf) {
            deleteBtn = `
              <button type="button" class="btn-icon btn-sm btn-delete-user" disabled title="You cannot delete your own account.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                </svg>
              </button>
            `;
          } else if (isOnlyAdmin) {
            deleteBtn = `
              <button type="button" class="btn-icon btn-sm btn-delete-user" disabled title="Cannot delete the only administrator.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                </svg>
              </button>
            `;
          } else {
            deleteBtn = `
              <button type="button" class="btn-icon btn-sm btn-delete-user btn-danger" onclick="SettingsPage.deleteUser(${user.id}, '${this.escapeHtml(user.email)}')" title="Delete user account">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3 6h18m-2 0v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6m3 0V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path>
                </svg>
              </button>
            `;
          }

          actionsHtml = `<div class="user-list-actions">${roleBtn}${deleteBtn}</div>`;
        }

        const dateStr = user.created_at
          ? new Date(user.created_at.replace(' ', 'T') + 'Z').toLocaleDateString()
          : '';

        return `
          <div class="user-list-row" id="user-row-${user.id}">
            <div class="user-list-main">
              <div class="user-list-avatar">${this.escapeHtml((user.email || 'U').charAt(0).toUpperCase())}</div>
              <div class="user-list-details">
                <div class="user-list-meta">
                  <strong class="user-list-email">${this.escapeHtml(user.email)}</strong>
                  ${isSelf ? '<span class="user-pill user-pill-self">You</span>' : ''}
                  <span class="user-pill ${isTargetAdmin ? 'user-pill-admin' : 'user-pill-member'}">
                    ${isTargetAdmin ? 'Admin' : 'Member'}
                  </span>
                </div>
                <span class="user-list-date">Added ${dateStr}</span>
              </div>
            </div>
            ${actionsHtml}
          </div>
        `;
      }).join('');
    } catch (error) {
      list.innerHTML = `<p class="settings-error">${this.escapeHtml(error.message)}</p>`;
    }
  },

  async loadTrackingSites(isAdmin) {
    const card = document.getElementById('trackingSettingsCard'); if (!card) return;
    card.hidden = !isAdmin; if (!isAdmin) return;
    const select = document.getElementById('trackingSiteSelect'); const data = await Api.getSites();
    select.innerHTML = (data.sites || []).map(s => `<option value="${this.escapeHtml(s.id)}">${this.escapeHtml(s.name || s.id)}</option>`).join('');
    await Promise.all([this.loadTrackingConfig(), this.loadTrackingServer()]);
  },

  async loadVersion(refresh = false) {
    const status = document.getElementById('updateStatus'); if (!status) return;
    const button = document.getElementById('updateCheckNow'); const link = document.getElementById('updateReleaseLink');
    if (refresh) { button.disabled = true; status.className = 'settings-feedback'; status.textContent = 'Checking GitHub…'; }
    try {
      const data = await Api.getVersion(refresh);
      document.getElementById('updateInstalled').textContent = data.installed || 'Development version';
      const isAdmin = 'latest' in data;
      document.getElementById('updateLatestRow').hidden = !isAdmin; button.hidden = !isAdmin;
      document.getElementById('updateLatest').textContent = data.latest ? data.latest.version : 'Unknown';
      link.href = data.latest ? data.latest.url : data.releases_url; link.hidden = !data.update_available;
      App.showUpdateDots(Boolean(data.update_available));
      this.latestVersion = data.update_available ? data.latest.version : null;
      const install = document.getElementById('updateInstall'); install.hidden = !this.latestVersion;
      if (this.latestVersion) install.textContent = `Install ${this.latestVersion}`;
      // The last update from the dashboard, and the version a rollback would bring back.
      const last = data.install || {};
      this.previousVersion = last.previous || null;
      const rollback = document.getElementById('updateRollback'); rollback.hidden = !this.previousVersion;
      if (this.previousVersion) rollback.textContent = `Go back to ${this.previousVersion}`;
      document.getElementById('updateManualLink').hidden = last.status !== 'broken';
      let message = ''; let tone = data.update_available ? 'success' : '';
      if (isAdmin && !data.latest) message = 'GitHub could not be reached. Try again later.';
      else if (last.status === 'broken') { message = `The update to ${last.to} failed and could not be undone. ${last.error} Restore the backup by hand, as the documentation explains.`; tone = 'error'; }
      else if (last.status === 'failed' && data.update_available) { message = `The update to ${last.to} failed, so Petitstats went back to ${last.from}. ${last.error}`; tone = 'error'; }
      else if (data.update_available) message = `Version ${data.latest.version} is available.`;
      else if (!data.installed) message = 'This copy runs from the source code, so it is not compared with releases.';
      else if (last.status === 'installed' && last.to === data.installed && !last.verified) message = `Petitstats was updated to ${last.to}, but your server could not reach itself to check that the new version runs. If something looks wrong, go back to ${last.from}.`;
      else if (isAdmin) message = 'You are using the latest version.';
      status.className = 'settings-feedback' + (tone ? ` settings-${tone}` : '');
      status.textContent = message;
      this.loadChangelog(data, refresh);
    } catch (e) { status.className = 'settings-feedback settings-error'; status.textContent = e.message; }
    finally { if (button) button.disabled = false; }
  },

  /** What the available update brings, from the release notes on GitHub. */
  async loadChangelog(data, refresh) {
    const section = document.getElementById('updateChangelog');
    if (!data.update_available) { section.hidden = true; return; }
    const changelog = await Api.getChangelog({ after: data.installed, upTo: data.latest.version, refresh }).catch(() => null);
    section.hidden = !changelog || !changelog.releases.length;
    if (!section.hidden) document.getElementById('updateChangelogList').replaceChildren(Changelog.render(changelog.releases));
  },

  /** Installs the latest release, one step per request so that no request runs long (see Updater.php). */
  async installUpdate() {
    const version = this.latestVersion; if (!version) return;
    if (!confirm(`Install Petitstats ${version}? Your analytics and settings are not touched, and a backup of the current files is kept. Petitstats is unavailable for a few seconds while the files are replaced.`)) return;
    const status = document.getElementById('updateStatus'); const problems = document.getElementById('updateProblems');
    const buttons = ['updateInstall', 'updateCheckNow', 'updateRollback'].map((id) => document.getElementById(id));
    buttons.forEach((b) => { b.disabled = true; }); problems.hidden = true;
    const steps = [['check', 'Checking that Petitstats can update itself…'], ['download', `Downloading ${version} and checking its files…`], ['backup', 'Backing up the current files…'], ['install', `Installing ${version}…`]];
    try {
      for (const [step, text] of steps) {
        status.className = 'settings-feedback'; status.textContent = text;
        const data = await Api.runUpdateStep(step);
        if (step === 'check' && data.problems.length) {
          problems.replaceChildren(...data.problems.map((problem) => Object.assign(document.createElement('li'), { textContent: problem })));
          problems.hidden = false; document.getElementById('updateManualLink').hidden = false;
          status.className = 'settings-feedback settings-error'; status.textContent = 'Petitstats cannot update itself on this server, so update it by hand instead.';
          return;
        }
      }
      status.className = 'settings-feedback settings-success'; status.textContent = `Petitstats ${version} is installed. Reloading the page…`;
      setTimeout(() => window.location.reload(), 1500);
    } catch (e) { status.className = 'settings-feedback settings-error'; status.textContent = e.message; }
    finally { buttons.forEach((b) => { b.disabled = false; }); }
  },

  async rollbackUpdate() {
    const version = this.previousVersion; if (!version) return;
    if (!confirm(`Go back to Petitstats ${version}? The files of the current version are replaced with the backup taken before the last update. Your analytics and settings are not touched.`)) return;
    const status = document.getElementById('updateStatus'); const rollback = document.getElementById('updateRollback');
    rollback.disabled = true; status.className = 'settings-feedback'; status.textContent = `Going back to ${version}…`;
    try {
      await Api.runUpdateStep('rollback');
      status.className = 'settings-feedback settings-success'; status.textContent = `Petitstats ${version} is back. Reloading the page…`;
      setTimeout(() => window.location.reload(), 1500);
    } catch (e) { status.className = 'settings-feedback settings-error'; status.textContent = e.message; rollback.disabled = false; }
  },

  selectTab(name) {
    document.querySelectorAll('[data-settings-tab]').forEach((tab) => { const selected = tab.dataset.settingsTab === name; tab.classList.toggle('is-active', selected); tab.setAttribute('aria-selected', String(selected)); });
    document.querySelectorAll('[data-settings-panel]').forEach((panel) => { panel.hidden = panel.dataset.settingsPanel !== name; });
  },

  async loadDatabaseConfig(isAdmin) {
    const card = document.getElementById('databaseSettingsCard'); if (!card) return;
    card.hidden = !isAdmin; if (!isAdmin) return;
    try {
      const { config } = await Api.getDatabaseConfig();
      document.getElementById('databaseDriver').value = config.driver || 'sqlite';
      document.getElementById('databaseHost').value = config.host || '';
      document.getElementById('databasePort').value = config.port || 3306;
      document.getElementById('databaseName').value = config.database || '';
      document.getElementById('databaseUsername').value = config.username || '';
      document.getElementById('databasePassword').value = '';
      this.toggleDatabaseFields();
    } catch (error) { this.setDatabaseFeedback(error.message, 'error'); }
  },

  databasePayload() {
    return { driver: document.getElementById('databaseDriver').value, host: document.getElementById('databaseHost').value.trim(), port: document.getElementById('databasePort').value, database: document.getElementById('databaseName').value.trim(), username: document.getElementById('databaseUsername').value.trim(), password: document.getElementById('databasePassword').value, create_database: document.getElementById('databaseCreateIfMissing').checked };
  },

  toggleDatabaseFields() {
    const form = document.getElementById('databaseSettingsForm'); const isRemote = document.getElementById('databaseDriver').value !== 'sqlite';
    form.hidden = !isRemote;
    form.querySelectorAll('input').forEach((input) => { input.disabled = !isRemote; });
    if (isRemote) {
      this.invalidateDatabaseTest();
    } else {
      this.databaseTested = true;
      document.getElementById('saveDatabaseConnector').disabled = false;
      this.setDatabaseFeedback('');
    }
  },

  invalidateDatabaseTest(showMessage = true) {
    const save = document.getElementById('saveDatabaseConnector');
    this.databaseTested = false;
    if (save) save.disabled = true;
    if (showMessage) this.setDatabaseFeedback('Test the current connection before saving.');
  },

  async testDatabase() {
    this.setDatabaseFeedback('Testing connection…');
    try { const result = await Api.databaseConnector('test', this.databasePayload()); this.databaseTested = true; document.getElementById('saveDatabaseConnector').disabled = false; const created = result.connection.database_created ? ' Database created.' : ''; this.setDatabaseFeedback(`${result.message}${created} Server version: ${result.connection.version}. You can now save this connector.`, 'success'); }
    catch (error) { this.setDatabaseFeedback(error.message, 'error'); }
  },

  async saveDatabase(event) {
    event?.preventDefault();
    const isRemote = document.getElementById('databaseDriver').value !== 'sqlite';
    if (isRemote && !this.databaseTested) { this.setDatabaseFeedback('Test the current connection before saving.', 'error'); return; }
    this.setDatabaseFeedback(isRemote ? 'Rechecking and saving connector…' : 'Saving SQLite connector…');
    try { const result = await Api.databaseConnector('save', this.databasePayload()); document.getElementById('databasePassword').value = ''; this.setDatabaseFeedback(result.message, 'success'); }
    catch (error) { this.setDatabaseFeedback(error.message, 'error'); }
  },

  setDatabaseFeedback(message, type = '') { const el = document.getElementById('databaseFeedback'); if (!el) return; el.textContent = message; el.className = 'settings-feedback'; if (type) el.classList.add(type === 'error' ? 'settings-error' : 'settings-success'); },

  async loadTrackingServer(body = null) {
    const card = document.getElementById('trackingServerCard'); const checkbox = document.getElementById('trackingTrustCloudflare');
    if (!card || !checkbox) return;
    const feedback = document.getElementById('trackingServerFeedback');
    try {
      const data = await Api.trackingServerSettings(body);
      card.hidden = false;
      checkbox.checked = data.trust_cloudflare; checkbox.disabled = data.forced_by_environment;
      let message = body ? 'Saved.' : '';
      if (data.forced_by_environment) message = 'Turned on by the PETITSTATS_TRUST_CLOUDFLARE environment variable.';
      else if (data.request_via_cloudflare && !data.trust_cloudflare) message = 'This dashboard is reaching you through Cloudflare: turn this on.';
      feedback.className = message === 'Saved.' ? 'settings-feedback settings-success' : 'settings-feedback';
      feedback.textContent = message;
    } catch (e) { feedback.className = 'settings-feedback settings-error'; feedback.textContent = e.message; }
    if (!checkbox.dataset.bound) { checkbox.dataset.bound = '1'; checkbox.addEventListener('change', () => this.loadTrackingServer({ trust_cloudflare: checkbox.checked })); }
  },

  async loadTrackingConfig() {
    const select = document.getElementById('trackingSiteSelect'); if (!select || !select.value) return;
    try { const data = await Api.getTrackingConfig(select.value); const site = data.site;
      this.setTrackingTags('domains', site.allowed_domains || []);
      this.setTrackingTags('internalIps', site.internal_ips || []);
      document.getElementById('trackingAllowLocalhost').checked = site.allow_localhost === true;
      document.getElementById('trackingRetention').value = site.retention_days || 395;
    } catch (e) { this.setTrackingFeedback(e.message, 'error'); }
  },

  async saveTracking(event) {
    event.preventDefault(); const select = document.getElementById('trackingSiteSelect');
    try { const data = await Api.updateSiteConfig({ id: select.value, allowed_domains: this.trackingTags.domains, allow_localhost: document.getElementById('trackingAllowLocalhost').checked, internal_ips: this.trackingTags.internalIps, retention_days: document.getElementById('trackingRetention').value, rotate_key: document.getElementById('trackingRotateKey').checked });
      this.setTrackingTags('domains', data.site.allowed_domains || []); this.setTrackingTags('internalIps', data.site.internal_ips || []);
      document.getElementById('trackingRotateKey').checked = false; document.getElementById('trackingSnippet').value = data.snippet; document.getElementById('trackingSnippetResult').hidden = false; this.setTrackingFeedback('Tracking settings saved.', 'success');
    } catch (e) { this.setTrackingFeedback(e.message, 'error'); }
  },

  setupTrackingTagEditor(kind, inputId, buttonId) {
    const input = document.getElementById(inputId); const button = document.getElementById(buttonId);
    if (!input || !button) return;
    const add = () => this.addTrackingTags(kind, input);
    button.addEventListener('click', add);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ',') { event.preventDefault(); add(); }
      if (event.key === 'Backspace' && !input.value && this.trackingTags[kind].length) this.removeTrackingTag(kind, this.trackingTags[kind].length - 1);
    });
    input.addEventListener('blur', () => { if (input.value.trim()) add(); });
  },

  addTrackingTags(kind, input) {
    const values = input.value.split(/[\s,]+/).map(value => value.trim()).filter(Boolean);
    if (!values.length) return;
    const existing = this.trackingTags[kind].map(value => value.toLowerCase());
    values.forEach(value => { if (!existing.includes(value.toLowerCase())) { this.trackingTags[kind].push(value); existing.push(value.toLowerCase()); } });
    input.value = ''; this.renderTrackingTags(kind);
  },

  setTrackingTags(kind, values) {
    this.trackingTags[kind] = [...new Set((Array.isArray(values) ? values : []).map(value => String(value).trim()).filter(Boolean))];
    this.renderTrackingTags(kind);
  },

  removeTrackingTag(kind, index) { this.trackingTags[kind].splice(index, 1); this.renderTrackingTags(kind); },

  renderTrackingTags(kind) {
    const list = document.getElementById(kind === 'domains' ? 'trackingDomainsList' : 'trackingInternalIpsList');
    if (!list) return;
    list.replaceChildren(...this.trackingTags[kind].map((value, index) => {
      const tag = document.createElement('span'); tag.className = 'tracking-tag';
      const text = document.createElement('span'); text.className = 'tracking-tag-value'; text.textContent = value;
      const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'tracking-tag-remove'; remove.setAttribute('aria-label', `Remove ${value}`); remove.title = `Remove ${value}`; remove.textContent = '×';
      remove.addEventListener('click', () => this.removeTrackingTag(kind, index)); tag.append(text, remove); return tag;
    }));
  },

  setTrackingFeedback(message, type = '') { const el=document.getElementById('trackingFeedback'); if (!el) return; el.textContent=message; el.className='settings-feedback'; if(type==='error')el.classList.add('settings-error'); if(type==='success')el.classList.add('settings-success'); },

  async invite(event) {
    event.preventDefault();
    const emailInput = document.getElementById('inviteEmail');
    const email = emailInput.value.trim();
    const feedback = document.getElementById('inviteFeedback');
    const result = document.getElementById('inviteResult');

    feedback.textContent = '';
    feedback.className = 'settings-feedback';

    try {
      const data = await Api.inviteUser(email);
      document.getElementById('inviteLink').value = data.invite_url;
      result.hidden = false;
      emailInput.value = '';
      feedback.textContent = `Invitation created for ${email}.`;
      this.load();
    } catch (error) {
      feedback.textContent = error.message;
      feedback.classList.add('settings-error');
    }
  },

  async toggleRole(userId, newRole, userEmail) {
    const isPromoting = newRole === 'admin';
    const message = isPromoting
      ? `Promote "${userEmail}" to Administrator?`
      : `Revoke Administrator privileges from "${userEmail}"? They will become a Member.`;

    if (!confirm(message)) return;

    this.setFeedback('Updating role…', '');

    try {
      const data = await Api.updateUserRole(userId, newRole);
      this.setFeedback(data.message || 'User role updated successfully.', 'success');
      await this.load();
    } catch (error) {
      this.setFeedback(error.message, 'error');
    }
  },

  async deleteUser(userId, userEmail) {
    const confirmMsg = `Are you sure you want to permanently delete user "${userEmail}"?\n\nThis account will lose access immediately.`;
    if (!confirm(confirmMsg)) return;

    this.setFeedback('Deleting user…', '');

    try {
      const data = await Api.deleteUser(userId);
      this.setFeedback(data.message || 'User deleted successfully.', 'success');
      await this.load();
    } catch (error) {
      this.setFeedback(error.message, 'error');
    }
  },

  mcpUrl: '',
  mcpClient: 'claude',

  /**
   * How to connect each assistant, one tile each. They all sign in with
   * OAuth, so these instructions never contain a secret.
   */
  mcpClients() {
    const url = this.mcpUrl;
    const name = 'petitstats';
    const vscode = JSON.stringify({ name, type: 'http', url });
    const steps = (items) => `<ol class="mcp-steps">${items.map((item) => `<li>${item}</li>`).join('')}</ol>`;
    const note = (html) => `<p class="mcp-note">${html}</p>`;
    const install = (href, label, brand, newTab = false) => `<a class="mcp-install" href="${this.escapeHtml(href)}"${newTab ? ' target="_blank" rel="noopener"' : ''}><span class="mcp-install-logo mcp-install-${brand}">${Icons.brand(brand, { size: 18 })}</span>${label}<span class="mcp-install-arrow">${Icons.get('arrow-up-right', { size: 16 })}</span></a>`;
    const byHand = (label, text) => `<details class="mcp-more"><summary>${label}</summary>${this.mcpCode(text)}</details>`;
    return [
      // claude.ai opens its "Add custom connector" dialog with these fields filled in.
      // A connector added on claude.ai also reaches Claude Code when it is signed in with a Claude account.
      { id: 'claude', label: 'Claude', detail: 'App, desktop, mobile and Claude Code', icon: { brand: 'claude' }, guide: () => steps([
        `Open Claude with the connector ready to add: ${install(`https://claude.ai/customize/connectors?${new URLSearchParams({ modal: 'add-custom-connector', connectorName: 'Petitstats', connectorUrl: url })}`, 'Add to Claude', 'claude', true)}`,
        'Click <strong>Add</strong>, then <strong>Connect</strong>, and allow access in Petitstats.',
      ]) + note('Petitstats then works in every Claude app on your account, including Claude Code signed in with your Claude account. Claude connects from the internet, so your Petitstats must be reachable over HTTPS. On Team and Enterprise plans, an Owner adds the connector in <strong>Organization settings → Connectors</strong>.')
        + byHand('Add it by hand: Customize → Connectors → Add custom connector, with this URL', url)
        + byHand('Using Claude Code with an API key? Run this, then /mcp → Authenticate', `claude mcp add --transport http ${name} ${url}`) },
      { id: 'chatgpt', label: 'ChatGPT', detail: 'Paid plans', icon: { brand: 'openai' }, guide: () => steps([
        'In <strong>Settings → Apps → Advanced settings</strong>, turn on <strong>Developer mode</strong>.',
        `Click <strong>Create app</strong>, name it <strong>Petitstats</strong>, choose <strong>OAuth</strong> and paste this URL:${this.mcpCode(url)}`,
        'Click <strong>Create</strong>, then allow access in Petitstats.',
      ]) + note('In a chat, turn the app on from the <strong>+</strong> menu.') },
      { id: 'codex', label: 'Codex', detail: 'App, CLI and IDE extension', icon: { brand: 'openai' }, guide: () => steps([
        'In Codex, open <strong>Settings → MCP</strong> and add a custom MCP server.',
        `Name it <strong>petitstats</strong>, choose <strong>Streamable HTTP</strong> and paste this URL:${this.mcpCode(url)}`,
        'Click <strong>Save</strong>, then <strong>Authenticate</strong>, and allow access in Petitstats.',
      ]) + byHand('Using the Codex CLI? Run these commands', `codex mcp add ${name} --url ${url}\ncodex mcp login ${name}`)
        + note('The Codex app, CLI and IDE extension share this configuration.') },
      { id: 'cursor', label: 'Cursor', detail: 'Editor', icon: { brand: 'cursor' }, guide: () => steps([
        `Add the server to Cursor: ${install(`cursor://anysphere.cursor-deeplink/mcp/install?name=${name}&config=${encodeURIComponent(btoa(JSON.stringify({ url })))}`, 'Add to Cursor', 'cursor')}`,
        'In Cursor’s MCP settings, click <strong>Connect</strong> next to petitstats, then allow access in Petitstats.',
      ]) + byHand('Add it by hand to ~/.cursor/mcp.json', JSON.stringify({ mcpServers: { [name]: { url } } }, null, 2)) },
      { id: 'vscode', label: 'VS Code', detail: 'GitHub Copilot', icon: { brand: 'vscode' }, guide: () => steps([
        `Add the server to VS Code: ${install(`vscode:mcp/install?${encodeURIComponent(vscode)}`, 'Add to VS Code', 'vscode')}`,
        'When VS Code starts the server, sign in to Petitstats and allow access.',
      ]) + byHand('Add it from the command line', `code --add-mcp '${vscode}'`) },
      { id: 'other', label: 'Other tools', detail: 'Your agent sets itself up', icon: { lucide: 'layout-grid' }, guide: () => this.otherToolsGuide(url) },
    ];
  },

  /**
   * Any other MCP client: the agent configures itself from a prompt, the URL
   * works for manual setups, and an access token covers clients that cannot sign in.
   */
  otherToolsGuide(url) {
    return `
      <div class="mcp-prompt">
        <div class="mcp-tool-name"><strong>Let your agent set itself up</strong></div>
        <p>Paste this into Gemini CLI, Windsurf, Antigravity or any agent that can edit its own configuration. It adds Petitstats and tells you how to sign in.</p>
        ${this.mcpCode(this.agentPrompt(url))}
      </div>
      <p class="mcp-lead">Or add this URL to your assistant’s MCP settings yourself:</p>
      ${this.mcpCode(url)}
      <div class="mcp-token">
        <div class="mcp-tool-name">${Icons.get('key-round', { size: 16 })}<strong>Assistant can’t sign in?</strong></div>
        <p>Create an access token and send it in an <code>Authorization: Bearer</code> header. It works until you revoke it below.</p>
        <div class="mcp-token-form">
          <input id="mcpTokenName" maxlength="80" placeholder="What will use it? e.g. Antigravity" autocomplete="off">
          <button type="button" class="btn-outline btn-sm" data-create-token>Create access token</button>
        </div>
        <div id="mcpTokenResult"></div>
      </div>`;
  },

  agentPrompt(url) {
    return [
      'Add the Petitstats MCP server to your MCP configuration:',
      '- Name: petitstats',
      `- URL: ${url}`,
      '- Transport: Streamable HTTP',
      '- Authentication: OAuth. Do not add an API key or an Authorization header: the server asks me to sign in to Petitstats the first time it is used.',
      'Use the command or configuration file of the tool you are running in (Claude Code, Codex, Gemini CLI, Cursor, VS Code, Windsurf, Antigravity…), then tell me how to complete the sign-in.',
      'If your tool cannot sign in to remote MCP servers with OAuth, or still gets "Unauthorized" after signing in, ask me for an access token (Petitstats, Settings → AI assistants → Other tools) and send it as an "Authorization: Bearer <token>" header instead. Do not write your own OAuth client.',
    ].join('\n');
  },

  mcpIcon(icon, size = 20) {
    return icon.brand ? Icons.brand(icon.brand, { size }) : Icons.get(icon.lucide, { size });
  },

  mcpCode(text) {
    const value = this.escapeHtml(text);
    // escapeHtml leaves quotes alone, which would end the attribute early.
    return `<div class="mcp-code"><pre>${value}</pre><button type="button" class="mcp-copy" data-copy="${value.replace(/"/g, '&quot;')}">Copy</button></div>`;
  },

  renderMcpTiles() {
    const tiles = document.getElementById('mcpClientTiles'); if (!tiles) return;
    tiles.innerHTML = this.mcpClients().map((client) => `
      <button type="button" role="tab" class="mcp-tile" data-mcp-client="${client.id}">
        <span class="mcp-tile-icon mcp-icon-${client.id}">${this.mcpIcon(client.icon, 22)}</span>
        <span class="mcp-tile-text"><strong>${client.label}</strong><small>${client.detail}</small></span>
      </button>`).join('');
    this.selectMcpClient(this.mcpClient);
  },

  selectMcpClient(id) {
    const client = this.mcpClients().find((c) => c.id === id); if (!client) return;
    this.mcpClient = id;
    document.querySelectorAll('[data-mcp-client]').forEach((tile) => { const selected = tile.dataset.mcpClient === id; tile.classList.toggle('is-active', selected); tile.setAttribute('aria-selected', String(selected)); });
    document.getElementById('mcpClientGuide').innerHTML = client.guide();
  },

  /** One listener for the whole tab: tiles, copy buttons, token creation and revocation. */
  handleAssistantsClick(event) {
    const target = event.target.closest('[data-mcp-client], [data-copy], [data-create-token], [data-revoke-app], [data-revoke-token]');
    if (!target) return;
    const data = target.dataset;
    if (data.mcpClient) this.selectMcpClient(data.mcpClient);
    else if (data.copy !== undefined) ClipboardHelper.copy(data.copy, target);
    else if (data.createToken !== undefined) this.createToken(target);
    else if (data.revokeApp) this.revokeAccess({ app: data.revokeApp });
    else if (data.revokeToken) this.revokeAccess({ id: Number(data.revokeToken) });
  },

  /** Icon of a connected assistant, recognised from the name it registered with. */
  assistantIcon(name) {
    const known = [[/claude/i, { brand: 'claude' }], [/cursor/i, { brand: 'cursor' }], [/windsurf|codeium/i, { brand: 'windsurf' }], [/gemini/i, { brand: 'gemini' }], [/chatgpt|openai/i, { brand: 'openai' }], [/visual studio|vs ?code/i, { brand: 'vscode' }]];
    return (known.find(([pattern]) => pattern.test(name)) || [null, { lucide: 'plug' }])[1];
  },

  async loadApi() {
    const list = document.getElementById('mcpAccessList'); if (!list) return;
    try {
      const data = await Api.accessTokens();
      if (this.mcpUrl !== data.mcp_url) {
        this.mcpUrl = data.mcp_url;
        this.renderMcpTiles();
      }
      const date = (value) => new Date(value.replace(' ', 'T') + 'Z').toLocaleDateString();
      const used = (value) => (value ? `last used ${date(value)}` : 'never used');
      const row = (icon, title, meta, access, action) => `
        <div class="mcp-access-row">
          <span class="mcp-access-icon">${this.mcpIcon(icon, 18)}</span>
          <div class="mcp-access-text"><div class="mcp-access-title">${title}</div><span>${meta}</span></div>
          <label class="mcp-access-write" title="Let it create and edit funnels, goals and journeys. It can never delete anything."><input type="checkbox" ${access}> Can edit funnels</label>
          ${action}
        </div>`;
      const access = (key, value, canWrite) => `data-allow-write="${key}" data-key="${value}"${canWrite ? ' checked' : ''}`;
      const rows = [
        ...data.apps.map((app) => row(this.assistantIcon(app.name), this.escapeHtml(app.name), `Connected ${date(app.connected_at)} · ${used(app.last_used_at)}`,
          access('app', this.escapeHtml(app.client_id), app.can_write),
          `<button type="button" class="btn-outline btn-sm" data-revoke-app="${this.escapeHtml(app.client_id)}">Disconnect</button>`)),
        ...data.tokens.map((token) => row({ lucide: 'key-round' }, `${this.escapeHtml(token.name)} <code>${this.escapeHtml(token.hint)}</code>`, `Access token · created ${date(token.created_at)} · ${used(token.last_used_at)}`,
          access('id', Number(token.id), token.can_write),
          `<button type="button" class="btn-outline btn-sm" data-revoke-token="${Number(token.id)}">Revoke</button>`)),
      ];
      list.innerHTML = rows.length ? rows.join('') : '<p class="mcp-empty">No assistant has access yet. Connect one above.</p>';
    } catch (error) { list.innerHTML = `<p class="settings-error">${this.escapeHtml(error.message)}</p>`; }
  },

  async createToken(button) {
    const input = document.getElementById('mcpTokenName');
    const result = document.getElementById('mcpTokenResult');
    button.disabled = true;
    try {
      const { token } = await Api.accessTokens('POST', { name: input.value.trim() || 'Access token' });
      input.value = '';
      const config = JSON.stringify({ mcpServers: { petitstats: { type: 'http', url: this.mcpUrl, headers: { Authorization: `Bearer ${token.token}` } } } }, null, 2);
      result.innerHTML = `
        <div class="mcp-token-result">
          <strong>Copy your token now: it will not be shown again.</strong>
          ${this.mcpCode(token.token)}
          <p>Configuration for assistants that accept a URL and headers (Windsurf and Antigravity name the field <code>serverUrl</code>):</p>
          ${this.mcpCode(config)}
        </div>`;
      await this.loadApi();
    } catch (error) { result.innerHTML = `<p class="settings-error">${this.escapeHtml(error.message)}</p>`; }
    button.disabled = false;
  },

  /** Lets a connected assistant or a token edit funnels, goals and journeys, or takes it back. */
  async allowWrite(input) {
    const { allowWrite: key, key: value } = input.dataset;
    input.disabled = true;
    try {
      await Api.accessTokens('PATCH', { [key]: key === 'id' ? Number(value) : value, can_write: input.checked });
      this.setApiFeedback(input.checked ? 'This connection can now create and edit funnels, goals and journeys.' : 'This connection can only read your analytics again.', 'success');
    } catch (error) { input.checked = !input.checked; this.setApiFeedback(error.message, 'error'); }
    input.disabled = false;
  },

  async revokeAccess(body) {
    if (!confirm('Revoke this access? The assistant will not be able to read your analytics anymore.')) return;
    try { await Api.accessTokens('DELETE', body); this.setApiFeedback('Access revoked.', 'success'); await this.loadApi(); }
    catch (error) { this.setApiFeedback(error.message, 'error'); }
  },

  setApiFeedback(message, type = '') { const el = document.getElementById('mcpAccessFeedback'); if (!el) return; el.textContent = message; el.className = 'settings-feedback'; if (type) el.classList.add(type === 'error' ? 'settings-error' : 'settings-success'); },

  setFeedback(message, type = '') {
    const fb = document.getElementById('usersListFeedback');
    if (!fb) return;
    fb.textContent = message;
    fb.className = 'settings-feedback';
    if (type === 'error') {
      fb.classList.add('settings-error');
    } else if (type === 'success') {
      fb.classList.add('settings-success');
    }
  },

  escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value || '';
    return node.innerHTML;
  }
};

window.SettingsPage = SettingsPage;
