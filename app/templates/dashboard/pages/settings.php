<div class="page-view" id="page-settings">
    <div class="settings-page-header">
        <div>
            <h2>Settings</h2>
            <p>Manage the people who are allowed to access Petitstats, as well as the settings for each website and where to store the data.</p>
        </div><a class="btn-outline" href="#websites">Back to websites</a>
    </div>
    <div class="settings-tabs" role="tablist" aria-label="Settings sections">
        <button class="settings-tab is-active" type="button" role="tab" aria-selected="true" aria-controls="settingsAccessPanel" id="settingsAccessTab" data-settings-tab="access">Access</button>
        <button class="settings-tab" type="button" role="tab" aria-selected="false" aria-controls="settingsTrackingPanel" id="settingsTrackingTab" data-settings-tab="tracking">Tracking</button>
        <button class="settings-tab" type="button" role="tab" aria-selected="false" aria-controls="settingsDatabasePanel" id="settingsDatabaseTab" data-settings-tab="database">Database</button>
        <button class="settings-tab" type="button" role="tab" aria-selected="false" aria-controls="settingsAssistantsPanel" id="settingsAssistantsTab" data-settings-tab="assistants">AI assistants</button>
        <button class="settings-tab" type="button" role="tab" aria-selected="false" aria-controls="settingsDisplayPanel" id="settingsDisplayTab" data-settings-tab="display">Display</button>
        <button class="settings-tab" type="button" role="tab" aria-selected="false" aria-controls="settingsUpdatesPanel" id="settingsUpdatesTab" data-settings-tab="updates">Updates<span class="update-dot update-dot--tab" data-update-dot title="A new Petitstats version is available" hidden></span></button>
    </div>
    <div class="settings-panel" id="settingsAccessPanel" role="tabpanel" aria-labelledby="settingsAccessTab" data-settings-panel="access">
        <section class="settings-card" id="inviteUserCard">
            <div class="settings-card-heading">
                <div>
                    <h3>Invite someone</h3>
                    <p>Only email addresses added here can create an account.</p>
                </div>
            </div>
            <form id="inviteUserForm" class="invite-form"><label for="inviteEmail">Email address</label>
                <div class="invite-form-row"><input id="inviteEmail" required type="email" placeholder="colleague@example.com" autocomplete="email"><button class="btn-primary" type="submit">Create invitation link</button></div>
            </form>
            <div class="invite-result" id="inviteResult" hidden><strong>Invitation link ready</strong>
                <p>Copy this link and send it to the invited person. It expires in 7 days.</p>
                <div class="invite-link-row"><input id="inviteLink" readonly><button type="button" class="btn-outline" id="copyInviteLink">Copy</button></div>
            </div>
            <p class="settings-feedback" id="inviteFeedback" role="status"></p>
        </section>
        <section class="settings-card">
            <div class="settings-card-heading">
                <div>
                    <h3>Authorized users</h3>
                    <p>Accounts that already have access to this workspace.</p>
                </div>
            </div>
            <p class="settings-feedback" id="usersListFeedback" role="status"></p>
            <div id="usersList" class="users-list">
                <p class="settings-muted">Loading users…</p>
            </div>
        </section>
    </div>
    <div class="settings-panel" id="settingsTrackingPanel" role="tabpanel" aria-labelledby="settingsTrackingTab" data-settings-panel="tracking" hidden>
        <section class="settings-card" id="trackingSettingsCard">
            <div class="settings-card-heading">
                <div>
                    <h3>Tracking & data protection</h3>
                    <p>Allowed domains, internal traffic, retention and the private script key.</p>
                </div>
            </div>
            <form id="trackingSettingsForm" class="tracking-form">
                <div class="tracking-field tracking-field--site"><label for="trackingSiteSelect">Website</label><select id="trackingSiteSelect"></select></div>
                <div class="tracking-field">
                    <label for="trackingDomainsInput">Allowed domains</label>
                    <div class="tracking-tag-editor" id="trackingDomainsEditor">
                        <div class="tracking-tag-list" id="trackingDomainsList" aria-live="polite"></div>
                        <div class="tracking-tag-add"><input id="trackingDomainsInput" placeholder="example.com" autocomplete="off"><button class="btn-outline btn-sm" id="addTrackingDomain" type="button">Add</button></div>
                    </div>
                    <small>Add one domain at a time. Only these domains can send events for this website.</small>
                </div>
                <label class="tracking-checkbox"><input id="trackingAllowLocalhost" type="checkbox"><span><strong>Accept events from localhost</strong><small>To test the snippet on your computer. Anyone can run a local page with your script key, so turn it off once the website is live.</small></span></label>
                <div class="tracking-field">
                    <label for="trackingInternalIpsInput">Internal IP addresses to ignore</label>
                    <div class="tracking-tag-editor" id="trackingInternalIpsEditor">
                        <div class="tracking-tag-list" id="trackingInternalIpsList" aria-live="polite"></div>
                        <div class="tracking-tag-add"><input id="trackingInternalIpsInput" placeholder="203.0.113.10" autocomplete="off"><button class="btn-outline btn-sm" id="addTrackingInternalIp" type="button">Add</button></div>
                    </div>
                    <small>Add one address at a time. They are visible only in the ignored traffic log.</small>
                </div>
                <div class="tracking-field tracking-field--retention"><label for="trackingRetention">Data retention</label>
                    <div class="tracking-number"><input id="trackingRetention" type="number" min="1" max="760" value="395"><span>days</span></div>
                </div>
                <label class="tracking-checkbox"><input id="trackingRotateKey" type="checkbox"><span><strong>Rotate the tracking key</strong><small>The current snippet will stop working until it is replaced.</small></span></label>
                <div class="tracking-actions"><button class="btn-primary" type="submit">Save tracking settings</button></div>
            </form>
            <div class="invite-result" id="trackingSnippetResult" hidden><strong>Updated tracking snippet</strong>
                <p>Replace the existing script tag if you rotated the key.</p>
                <div class="invite-link-row"><input id="trackingSnippet" readonly><button type="button" class="btn-outline" id="copyTrackingSnippet">Copy</button></div>
            </div>
            <p class="settings-feedback" id="trackingFeedback" role="status"></p>
        </section>
        <section class="settings-card" id="trackingServerCard" hidden>
            <div class="settings-card-heading">
                <div>
                    <h3>Visitor IP addresses</h3>
                    <p>Applies to every website. IP addresses are never stored, and only serve for the rate limit, the visitor key and the internal traffic filter.</p>
                </div>
            </div>
            <label class="tracking-checkbox"><input id="trackingTrustCloudflare" type="checkbox"><span><strong>Petitstats is behind Cloudflare</strong><small id="trackingTrustCloudflareHelp">Turn on when this Petitstats domain goes through Cloudflare, whatever your tracked websites use. Visitor addresses are then read from Cloudflare's CF-Connecting-IP header. Leave it off otherwise, since anyone can send this header with a fake address.</small></span></label>
            <p class="settings-feedback" id="trackingServerFeedback" role="status"></p>
        </section>
    </div>
    <div class="settings-panel" id="settingsDatabasePanel" role="tabpanel" aria-labelledby="settingsDatabaseTab" data-settings-panel="database" hidden>
        <section class="settings-card" id="databaseSettingsCard">
            <div class="settings-card-heading">
                <div>
                    <h3>Analytics database</h3>
                    <p>Choose where Petitstats stores new analytics data. SQLite remains local; MySQL and MariaDB use one shared database with isolated websites.</p>
                </div>
            </div>
            <div class="database-engine-field"><label for="databaseDriver">Database engine</label><select id="databaseDriver">
                    <option value="sqlite">SQLite (local file)</option>
                    <option value="mysql">MySQL</option>
                    <option value="mariadb">MariaDB</option>
                </select></div>
            <form id="databaseSettingsForm" class="database-form" hidden>
                <div class="database-remote-fields" id="databaseRemoteFields">
                    <div class="tracking-field"><label for="databaseHost">Host</label><input id="databaseHost" autocomplete="off" placeholder="localhost"></div>
                    <div class="tracking-field"><label for="databasePort">Port</label><input id="databasePort" type="number" min="1" max="65535" value="3306"></div>
                    <div class="tracking-field"><label for="databaseName">Database name</label><input id="databaseName" autocomplete="off" placeholder="petitstats"></div>
                    <div class="tracking-field"><label for="databaseUsername">Username</label><input id="databaseUsername" autocomplete="username" placeholder="db_user"></div>
                    <div class="tracking-field database-password"><label for="databasePassword">Password</label><input id="databasePassword" type="password" autocomplete="new-password" placeholder="Leave blank to keep the saved password"><small>Your password is never returned to the browser after it is saved.</small></div>
                </div>
                <label class="database-checkbox"><input id="databaseCreateIfMissing" type="checkbox" checked><span><strong>Create the database if it is missing</strong><small>Requires the database user to have the <code>CREATE</code> permission.</small></span></label>
                <div class="database-actions"><button class="btn-outline" id="testDatabaseConnection" type="button">Test connection</button></div>
            </form>
            <div class="database-actions database-save-action"><button class="btn-primary" id="saveDatabaseConnector" type="button">Save database connector</button></div>
            <p class="settings-feedback" id="databaseFeedback" role="status"></p>
            <p class="settings-muted database-note">Changing connector does not migrate existing SQLite data automatically. Keep a backup of <code>data/</code> before switching.</p>
        </section>
    </div>
    <div class="settings-panel" id="settingsAssistantsPanel" role="tabpanel" aria-labelledby="settingsAssistantsTab" data-settings-panel="assistants" hidden>
        <section class="settings-card" id="mcpConnectCard">
            <div class="settings-card-heading">
                <div>
                    <h3>Connect an AI assistant</h3>
                    <p>Ask Claude, ChatGPT, Cursor and others about your traffic. They sign in with your Petitstats account and only read your analytics, unless you let one edit your funnels below.</p>
                </div>
            </div>
            <div class="mcp-tiles" id="mcpClientTiles" role="tablist" aria-label="Assistants"></div>
            <div class="mcp-guide" id="mcpClientGuide" role="tabpanel" aria-live="polite"></div>
        </section>
        <section class="settings-card">
            <div class="settings-card-heading">
                <div>
                    <h3>Authorized access</h3>
                    <p>Assistants and access tokens that can read your analytics. Turn on <strong>Can edit funnels</strong> to let one create and edit funnels, goals and journeys: no assistant can ever delete anything. Revoking one cuts its access immediately.</p>
                </div>
            </div>
            <p class="settings-feedback" id="mcpAccessFeedback" role="status"></p>
            <div id="mcpAccessList" class="mcp-access-list"></div>
        </section>
    </div>
    <div class="settings-panel" id="settingsDisplayPanel" role="tabpanel" aria-labelledby="settingsDisplayTab" data-settings-panel="display" hidden>
        <section class="settings-card">
            <div class="settings-card-heading">
                <div>
                    <h3>Time format</h3>
                    <p>How times are written across the dashboard. This choice is saved in this browser only.</p>
                </div>
            </div>
            <div class="display-field"><label for="timeFormatSelect">Times</label><select id="timeFormatSelect">
                <option value="24h">24-hour (17:25)</option>
                <option value="12h">12-hour (5:25 PM)</option>
            </select></div>
            <p class="settings-feedback" id="timeFormatFeedback" role="status"></p>
        </section>
    </div>
    <div class="settings-panel" id="settingsUpdatesPanel" role="tabpanel" aria-labelledby="settingsUpdatesTab" data-settings-panel="updates" hidden>
        <section class="settings-card" id="updatesCard">
            <div class="settings-card-heading">
                <div>
                    <h3>Petitstats version</h3>
                    <p>Your server asks GitHub for the latest release at most twice a day, and only while an administrator uses the dashboard.</p>
                </div>
            </div>
            <dl class="update-versions">
                <div><dt>Installed</dt><dd id="updateInstalled">…</dd></div>
                <div id="updateLatestRow" hidden><dt>Latest release</dt><dd id="updateLatest">…</dd></div>
            </dl>
            <p class="settings-feedback" id="updateStatus" role="status"></p>
            <ul class="update-problems" id="updateProblems" hidden></ul>
            <div class="update-changelog" id="updateChangelog" hidden>
                <h4>What's new</h4>
                <div id="updateChangelogList"></div>
            </div>
            <div class="update-actions">
                <button type="button" class="btn-primary" id="updateInstall" hidden>Install update</button>
                <a class="btn-outline" id="updateReleaseLink" href="https://github.com/axthauvin/petitstats/releases" target="_blank" rel="noopener" hidden>Release on GitHub ↗</a>
                <a class="btn-outline" id="updateManualLink" href="<?= \Petitstats\Http\Docs::url('operations', 'updating-by-hand') ?>" target="_blank" rel="noopener" hidden>How to update by hand ↗</a>
                <button type="button" class="btn-outline" id="updateCheckNow" hidden>Check now</button>
                <button type="button" class="btn-outline" id="updateRollback" hidden>Go back to the previous version</button>
            </div>
        </section>
    </div>
</div>
