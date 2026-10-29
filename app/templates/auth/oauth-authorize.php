<?php
/**
 * @var string|null $error
 * @var array<string, mixed> $params
 * @var string $clientName
 * @var string $email
 * @var string $redirectHost
 * @var bool $isLocalApp
 */

use Petitstats\Auth\Csrf;

$h = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
?>
<?php if ($error !== null): ?>
    <h1>Connection failed</h1>
    <div class="auth-error"><?= $h($error) ?></div>
<?php else: ?>
    <span class="eyebrow">Connect an assistant</span>
    <h1>Allow <?= $h($clientName) ?> to read your analytics?</h1>
    <p>You are signed in as <strong><?= $h($email) ?></strong>.</p>
    <ul class="oauth-scopes">
        <li><strong>Read-only.</strong> It can read the statistics of every website in this workspace. It cannot change anything, unless you let it edit funnels, goals and journeys in <strong>Settings → AI assistants</strong>. It can never delete anything.</li>
        <li>You can disconnect it at any time in <strong>Settings → AI assistants</strong>.</li>
    </ul>
    <?php if ($isLocalApp): ?>
        <div class="auth-error">Access is handed to an application running on this computer. Only continue if you just started this connection from your assistant.</div>
    <?php else: ?>
        <p class="oauth-redirect">After you allow access, you will return to <strong><?= $h($redirectHost) ?></strong>.</p>
    <?php endif; ?>
    <form method="post" action="/oauth/authorize.php" class="oauth-actions">
        <?php foreach ($params as $name => $value): ?>
            <input type="hidden" name="<?= $h((string) $name) ?>" value="<?= $h(is_string($value) ? $value : '') ?>">
        <?php endforeach; ?>
        <?= Csrf::field() ?>
        <button type="submit" name="decision" value="deny" class="oauth-deny">Cancel</button>
        <button type="submit" name="decision" value="allow">Allow access</button>
    </form>
<?php endif; ?>
