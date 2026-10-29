<?php
// One window to add a website: start from scratch or bring the history of another analytics service.
// The steps shown depend on the path, see dashboard/src/assets/js/site-setup.js.
$docsImport = \Petitstats\Http\Docs::url('importing');

// Services to import from. Logos and brand colours from Simple Icons (simpleicons.org, CC0).
$importSources = [
    'umami' => [
        'name' => 'Umami', 'desc' => 'ZIP export of a website', 'ready' => true, 'color' => '#000000',
        'icon' => 'M2.203 8.611H.857a.845.845 0 0 0-.841.841v.858a13.31 13.31 0 0 0-.016.6c0 6.627 5.373 12 12 12 6.527 0 11.837-5.212 11.996-11.701 0-.025.004-.05.004-.075V9.452a.845.845 0 0 0-.841-.841h-1.346c-1.159-4.329-5.112-7.521-9.805-7.521-4.692 0-8.645 3.192-9.805 7.521Zm18.444 0H3.37c1.127-3.702 4.57-6.399 8.638-6.399 4.069 0 7.512 2.697 8.639 6.399Z',
    ],
    'matomo' => [
        'name' => 'Matomo', 'desc' => 'Visits log, JSON or CSV', 'ready' => true, 'color' => '#3152A0',
        'icon' => 'M6.664 15.37a3.336 3.336 0 0 1-3.332 3.332C1.495 18.702 0 17.208 0 15.37s1.495-3.333 3.332-3.333a3.338 3.338 0 0 1 3.332 3.333zm11.565-3.644a3.658 3.658 0 0 1-1.987.591 3.642 3.642 0 0 1-1.872-.529l.008.012a3.728 3.728 0 0 1-1.235-1.19l-2.612-3.693a.17.17 0 0 1-.027-.033A3.312 3.312 0 0 0 7.67 5.298a3.318 3.318 0 0 0-2.848 1.586.146.146 0 0 1-.021.028l-3.428 5.343a3.663 3.663 0 0 1 5.094 1.18.13.13 0 0 1 .015.018l2.756 3.869a3.305 3.305 0 0 0 2.699 1.38 3.31 3.31 0 0 0 2.711-1.379l.009-.013c.073-.103.137-.202.195-.305l1.442-2.255 1.935-3.024zm5.275 1.902l-.014-.028-.044-.066a1.109 1.109 0 0 0-.029-.044l-3.525-5.37c.024.168.052.335.052.51 0 .741-.219 1.457-.634 2.068l-2.803 4.38 1.416 2.179-.002.002a.131.131 0 0 1 .024.028 3.338 3.338 0 0 0 2.723 1.415A3.335 3.335 0 0 0 24 15.37c0-.613-.171-1.216-.496-1.742zm-7.262-1.666a3.336 3.336 0 0 0 3.332-3.333 3.336 3.336 0 0 0-3.332-3.332 3.336 3.336 0 0 0-3.332 3.332 3.338 3.338 0 0 0 3.332 3.333z',
    ],
    'google_analytics' => [
        'name' => 'Google Analytics', 'ready' => false, 'color' => '#E37400',
        'icon' => 'M22.84 2.9982v17.9987c.0086 1.6473-1.3197 2.9897-2.967 2.9984a2.9808 2.9808 0 01-.3677-.0208c-1.528-.226-2.6477-1.5558-2.6105-3.1V3.1204c-.0369-1.5458 1.0856-2.8762 2.6157-3.1 1.6361-.1915 3.1178.9796 3.3093 2.6158.014.1201.0208.241.0202.3619zM4.1326 18.0548c-1.6417 0-2.9726 1.331-2.9726 2.9726C1.16 22.6691 2.4909 24 4.1326 24s2.9726-1.3309 2.9726-2.9726-1.331-2.9726-2.9726-2.9726zm7.8728-9.0098c-.0171 0-.0342 0-.0513.0003-1.6495.0904-2.9293 1.474-2.891 3.1256v7.9846c0 2.167.9535 3.4825 2.3505 3.763 1.6118.3266 3.1832-.7152 3.5098-2.327.04-.1974.06-.3983.0593-.5998v-8.9585c.003-1.6474-1.33-2.9852-2.9773-2.9882z',
    ],
    'plausible' => [
        'name' => 'Plausible', 'ready' => false, 'color' => '#5850EC',
        'icon' => 'M12.1835.0017c-.6378-.0097-1.2884.022-1.7246.0996C8.601.424 7.035 1.2116 5.7384 2.4782 4.406 3.7806 3.582 5.299 3.1818 7.1929l-.1387.6445c-.0118 5.3872-.0233 10.7744-.035 16.1617.2914.0081.591-.0392.8416-.0606 2.348-.2868 4.3442-1.7083 5.4315-3.8651.2749-.5497.472-1.182.6094-1.9707.1135-.6691.1195-.8915.1016-4.3807l-.0176-3.6737.1425-.3574c.1972-.49.7425-1.0352 1.2324-1.2324l.3574-.1426 3.3457-.0058c1.8401 0 3.4545-.025 3.58-.0489.5854-.1135 1.2118-.6027 1.4628-1.1464.0717-.1494.1671-.4415.209-.6387.0657-.3286.0604-.4186-.0352-.789-.2987-1.0993-1.3503-2.6234-2.4257-3.5136C16.6247 1.1638 15.2798.4887 13.828.1482c-.3824-.0866-1.0067-.1368-1.6445-.1465zm8.5369 6.8006c-.0506.1798-.098.3662-.172.5215-.3358.7278-1.0382 1.2776-1.8221 1.4296-3.6737.0566-2.5392.0561-3.6737.0566l-3.248.0059-.2695.1074c-.3135.1262-.827.6397-.9531.9531l-.1074.2676.0175 3.576c.0149 2.8888.007 3.5821-.0605 4.125a8.9918 8.9918 0 0 0 1.5683.1386c4.9662.0001 8.992-4.0258 8.992-8.992a8.9918 8.9918 0 0 0-.2715-2.1893Z',
    ],
    'simple_analytics' => [
        'name' => 'Simple Analytics', 'ready' => false, 'color' => '#FF4F64',
        'icon' => 'M1.019 13.019h3.849V24h-3.85zm8.943-6.68h3.85V24h-3.85zM19.132 0h3.85v24h-3.85z',
    ],
];
?>
<div class="modal-overlay" id="siteSetupModal" data-static-backdrop>
    <div class="modal-dialog site-setup-dialog" role="dialog" aria-modal="true" aria-labelledby="siteSetupTitle">
        <div class="modal-header site-setup-header">
            <div>
                <h3 class="modal-title" id="siteSetupTitle">Add a website</h3>
                <p class="site-setup-subtitle" id="siteSetupSubtitle">Start collecting visits, or bring your history from another analytics service.</p>
            </div>
            <button type="button" class="btn-close" data-close-modal title="Close">✕</button>
        </div>

        <ol class="site-setup-stepper" id="siteSetupStepper" aria-label="Steps"></ol>

        <div class="modal-body site-setup-body">
            <div class="site-setup-error" id="siteSetupError" role="alert" hidden></div>

            <!-- How to start -->
            <section class="setup-step" data-setup-step="start">
                <div class="setup-choice-grid">
                    <button type="button" class="setup-choice" data-setup-path="new">
                        <span class="setup-choice-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        </span>
                        <span class="setup-choice-title">Start fresh</span>
                        <span class="setup-choice-desc">Create the website and get its tracking tag. Visits show up as soon as the tag is on your pages.</span>
                    </button>
                    <button type="button" class="setup-choice" data-setup-path="import">
                        <span class="setup-choice-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </span>
                        <span class="setup-choice-title">Import past data</span>
                        <span class="setup-choice-desc">Moving from Umami or Matomo? Bring your history into a new website or one you already track.</span>
                        <span class="setup-choice-logos" aria-hidden="true">
                            <?php foreach (array_filter($importSources, static fn(array $s): bool => $s['ready']) as $source): ?>
                                <span class="provider-logo" style="--brand: <?= $source['color'] ?>;" title="<?= htmlspecialchars($source['name']) ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="<?= $source['icon'] ?>"/></svg>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    </button>
                </div>
            </section>

            <!-- Import source and file -->
            <section class="setup-step" data-setup-step="source" hidden>
                <div class="setup-label">Where does your data come from?</div>
                <div class="providers-grid" id="ssProviders">
                    <?php foreach ($importSources as $id => $source): ?>
                        <?php $tag = $source['ready'] ? 'button' : 'div'; ?>
                        <<?= $tag ?> <?= $source['ready'] ? 'type="button" data-provider="' . $id . '"' : 'aria-disabled="true"' ?> class="provider-card<?= $source['ready'] ? '' : ' disabled' ?>" style="--brand: <?= $source['color'] ?>;">
                            <span class="provider-logo" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="<?= $source['icon'] ?>"/></svg>
                            </span>
                            <span class="provider-text">
                                <span class="provider-name"><?= htmlspecialchars($source['name']) ?></span>
                                <?php if ($source['ready']): ?>
                                    <span class="provider-desc"><?= htmlspecialchars($source['desc']) ?></span>
                                <?php else: ?>
                                    <span class="provider-badge">Soon</span>
                                <?php endif; ?>
                            </span>
                        </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
                <p class="setup-help setup-contribute">
                    Don't see your analytics service? <a href="https://github.com/axthauvin/petitstats/issues/new" target="_blank" rel="noopener">Suggest it</a>
                    or <a href="https://github.com/axthauvin/petitstats" target="_blank" rel="noopener">contribute an importer</a> on GitHub ↗
                </p>

                <div class="setup-upload" id="ssUpload" hidden>
                    <div class="setup-label" id="ssUploadLabel">Your export</div>
                    <p class="setup-help">
                        <span id="ssUploadHelp"></span>
                        <a href="<?= htmlspecialchars($docsImport) ?>" data-base="<?= htmlspecialchars($docsImport) ?>" id="ssUploadDocs" target="_blank" rel="noopener">How to export ↗</a>
                    </p>

                    <div class="import-dropzone" id="ssDropzone" tabindex="0" role="button" aria-describedby="ssDropzoneHint">
                        <input type="file" id="ssFileInput" hidden>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <div class="dropzone-title">Drop your export here or <u>browse</u></div>
                        <div class="dropzone-hint" id="ssDropzoneHint"></div>
                    </div>

                    <div class="setup-file" id="ssFile" hidden>
                        <div class="setup-file-row">
                            <div>
                                <div class="setup-file-name" id="ssFileName"></div>
                                <div class="setup-file-status" id="ssFileStatus"></div>
                            </div>
                            <button type="button" class="file-remove-btn" id="ssFileRemove" title="Choose another file">✕</button>
                        </div>
                        <dl class="setup-summary" id="ssSummary" hidden>
                            <div><dt>Visits</dt><dd id="ssSummaryVisits"></dd></div>
                            <div><dt>Period</dt><dd id="ssSummaryPeriod"></dd></div>
                            <div><dt>Website</dt><dd id="ssSummaryHost"></dd></div>
                        </dl>
                    </div>

                    <details class="setup-advanced" id="ssServerPathBox">
                        <summary>Use a file already on the server</summary>
                        <input type="text" id="ssServerPath" class="search-input setup-input mono" placeholder="/path/to/export.zip" autocomplete="off">
                        <p class="setup-help">For exports too large to upload. Give the path of a file or folder on this server.</p>
                    </details>
                </div>
            </section>

            <!-- Website -->
            <section class="setup-step" data-setup-step="website" hidden>
                <div class="setup-segmented" id="ssTargetToggle" role="radiogroup" aria-label="Destination" hidden>
                    <button type="button" role="radio" aria-checked="true" data-target="new">New website</button>
                    <button type="button" role="radio" aria-checked="false" data-target="existing">Existing website</button>
                </div>

                <div id="ssNewSiteFields">
                    <p class="setup-detected" id="ssDetectedHint" hidden>We filled these in from your export. Check them before you continue.</p>
                    <label class="setup-field">
                        <span class="setup-field-label">Name</span>
                        <input type="text" id="ssSiteName" class="search-input setup-input" placeholder="My blog" autocomplete="off">
                    </label>
                    <label class="setup-field">
                        <span class="setup-field-label">Domain</span>
                        <input type="text" id="ssSiteDomain" class="search-input setup-input" placeholder="example.com" autocomplete="off" inputmode="url">
                        <span class="setup-help">Only pages on this domain can send visits. You can allow more domains later in Settings.</span>
                    </label>
                    <details class="setup-advanced">
                        <summary>Website identifier <code id="ssSiteIdPreview"></code></summary>
                        <input type="text" id="ssSiteId" class="search-input setup-input mono" placeholder="my_blog" autocomplete="off">
                        <p class="setup-help">Used in the tracking tag. Letters, numbers, dashes and underscores. It cannot be changed later.</p>
                    </details>
                </div>

                <label class="setup-field" id="ssExistingSiteField" hidden>
                    <span class="setup-field-label">Website</span>
                    <select id="ssExistingSite" class="search-input setup-input"></select>
                    <span class="setup-help">The imported visits are added to the ones this website already has.</span>
                </label>
            </section>

            <!-- History kept after the import -->
            <section class="setup-step" data-setup-step="history" hidden>
                <div class="setup-notice">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p id="ssHistoryIntro"></p>
                </div>
                <div class="setup-options" role="radiogroup">
                    <label class="setup-option">
                        <input type="radio" name="ssRetention" value="extend" checked>
                        <span>
                            <span class="setup-option-title" id="ssExtendTitle"></span>
                            <span class="setup-option-desc" id="ssExtendDesc"></span>
                        </span>
                    </label>
                    <label class="setup-option">
                        <input type="radio" name="ssRetention" value="keep">
                        <span>
                            <span class="setup-option-title" id="ssKeepTitle"></span>
                            <span class="setup-option-desc" id="ssKeepDesc"></span>
                        </span>
                    </label>
                </div>
            </section>

            <!-- Working -->
            <section class="setup-step setup-working" data-setup-step="working" hidden>
                <span class="setup-spinner" aria-hidden="true"></span>
                <div class="setup-working-title" id="ssWorkingTitle">Importing your data</div>
                <p class="setup-help">Large exports can take a minute. Keep this window open.</p>
            </section>

            <!-- Done -->
            <section class="setup-step" data-setup-step="done" hidden>
                <div class="setup-done-head">
                    <span class="setup-done-icon" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </span>
                    <div>
                        <div class="setup-done-title" id="ssDoneTitle"></div>
                        <p class="setup-help" id="ssDoneText"></p>
                    </div>
                </div>

                <dl class="setup-summary setup-results" id="ssResults" hidden>
                    <div><dt>Visits</dt><dd id="ssResultVisits"></dd></div>
                    <div><dt>Pageviews</dt><dd id="ssResultPageviews"></dd></div>
                    <div><dt>Events</dt><dd id="ssResultEvents"></dd></div>
                    <div><dt>Period</dt><dd id="ssResultPeriod"></dd></div>
                </dl>

                <div id="ssSnippetBlock" hidden>
                    <div class="setup-label">Add this tag to the &lt;head&gt; of every page</div>
                    <div class="code-block-wrapper">
                        <div class="code-block-header">
                            <div class="code-block-dots">
                                <span class="code-dot code-dot-red"></span>
                                <span class="code-dot code-dot-yellow"></span>
                                <span class="code-dot code-dot-green"></span>
                                <span class="code-block-title">Tracking tag</span>
                            </div>
                            <button type="button" class="btn-copy-code" data-copy-target="#createdSiteSnippet" title="Copy tracking tag">
                                <svg class="copy-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                <svg class="check-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <span class="copy-text">Copy</span>
                            </button>
                        </div>
                        <pre class="code-box" id="createdSiteSnippet"></pre>
                    </div>
                </div>
            </section>
        </div>

        <div class="modal-footer site-setup-footer" id="ssFooter">
            <button type="button" class="btn-outline" id="ssBack">Back</button>
            <button type="button" class="btn-primary" id="ssNext">Continue</button>
        </div>
    </div>
</div>
