<?php
/**
 * Page around a single card: sign-in, invitation, onboarding and OAuth consent.
 *
 * @var string $title
 * @var string $content
 * @var string|null $cardClass
 * @var list<string>|null $headScripts
 * @var list<string>|null $scripts
 */

use Petitstats\Http\Asset;

?><!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?></title>
        <link rel="icon" type="image/png" sizes="512x512" href="/favicon.png">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="apple-touch-icon" href="/favicon.png">
        <link rel="stylesheet" href="<?= Asset::url('/dashboard/src/assets/css/auth.css') ?>">
        <?php foreach ($headScripts ?? [] as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    </head>
    <body>
        <main class="auth-card<?= isset($cardClass) ? ' ' . htmlspecialchars($cardClass) : '' ?>">
            <img src="/dashboard/src/assets/logo.svg" width="44" height="44" alt="Petitstats">
            <?= $content ?>
        </main>
        <?php foreach ($scripts ?? [] as $script): ?>
            <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    </body>
</html>
