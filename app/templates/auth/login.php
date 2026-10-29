<?php
/** @var string $error */

use Petitstats\Auth\Csrf;

?>
<h1>Sign in</h1>
<p>Access your Petitstats dashboard.</p>
<?php if ($error): ?>
    <div class="auth-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<form method="post">
    <?= Csrf::field() ?>
    <label>Email address
        <input required type="email" name="email" autocomplete="email">
    </label>
    <label>Password
        <input required type="password" name="password" autocomplete="current-password">
    </label>
    <button type="submit">Sign in</button>
</form>
