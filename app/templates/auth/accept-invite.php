<?php
/**
 * @var string $error
 * @var string $email
 * @var string $token
 */

use Petitstats\Auth\Csrf;

?>
<h1>Create your access</h1>
<?php if ($error): ?>
    <div class="auth-error"><?= htmlspecialchars($error) ?></div>
<?php else: ?>
    <p>You are joining Petitstats as <strong><?= htmlspecialchars($email) ?></strong>.</p>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label>Choose a password
            <span class="password-input">
                <input required minlength="10" type="password" name="password" autocomplete="new-password" data-password-strength>
                <button type="button" class="password-toggle" aria-label="Show password">Show</button>
            </span>
        </label>
        <div class="password-strength" aria-live="polite">
            <div class="password-strength-track">
                <span></span>
            </div>
            <p>Enter a password</p>
        </div>
        <button type="submit">Create my account</button>
    </form>
<?php endif; ?>
