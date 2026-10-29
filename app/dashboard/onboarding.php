<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Auth\Csrf;
use Petitstats\Database\DatabaseConfig;
use Petitstats\Http\Asset;
use Petitstats\Http\View;

require_once __DIR__ . '/../vendor/autoload.php';
Auth::startSession();
$step = $_GET['step'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'account' : 'welcome');
if (Auth::hasDatabase() && $step !== 'database') {
    header('Location: ' . (Auth::user() ? '/dashboard/' : '/dashboard/login.php'));
    exit;
}
$error = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !Csrf::isValid()) {
    $error = 'This page expired. Please try again.';
    $isPost = false;
}
if ($step === 'database') {
    $user = Auth::user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('Location: /dashboard/login.php');
        exit;
    }
}
if ($isPost && $step === 'account') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!Auth::validEmail($email)) {
        $error = 'Enter a valid email address.';
    } elseif ($passwordError = Auth::passwordError($password)) {
        $error = $passwordError;
    } else {
        Auth::loginById(Auth::createUser($email, $password, 'admin'));
        header('Location: /dashboard/onboarding.php?step=database');
        exit;
    }
}
if ($isPost && $step === 'database') {
    try {
        DatabaseConfig::save([
            'driver' => $_POST['driver'] ?? 'sqlite',
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? 3306,
            'database' => $_POST['database'] ?? '',
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
            'create_database' => !empty($_POST['create_database']),
        ]);
        header('Location: /dashboard/#websites');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
$databaseDriver = in_array($_POST['driver'] ?? '', ['mysql', 'mariadb'], true) ? $_POST['driver'] : 'sqlite';
$scripts = [Asset::url('/dashboard/src/assets/js/password-strength.js')];
if ($step === 'database') {
    $scripts[] = Asset::url('/dashboard/src/assets/js/onboarding-database.js');
}
echo View::render('auth/onboarding', [
    'title' => 'Set up Petitstats',
    'step' => $step,
    'error' => $error,
    'databaseDriver' => $databaseDriver,
    'databaseStage' => $error !== '' && $databaseDriver !== 'sqlite' ? 'config' : 'choice',
    // Values to fill the server form back in after a failed connection test.
    'server' => [
        'host' => (string) ($_POST['host'] ?? ''),
        'port' => (string) ($_POST['port'] ?? '3306'),
        'database' => (string) ($_POST['database'] ?? ''),
        'username' => (string) ($_POST['username'] ?? ''),
        'create_database' => !isset($_POST['create_database']) || !empty($_POST['create_database']),
    ],
    'cardClass' => $step === 'database' ? 'auth-card--database' : null,
    'headScripts' => ['https://unpkg.com/lucide@latest', Asset::url('/dashboard/src/assets/js/icons.js')],
    'scripts' => $scripts,
], 'layouts/auth');
