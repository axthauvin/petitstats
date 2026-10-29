<?php

declare(strict_types=1);

use Delight\Auth\AuthException;
use Delight\Auth\TooManyRequestsException;
use Petitstats\Auth\Auth;
use Petitstats\Auth\Csrf;
use Petitstats\Http\View;

require_once __DIR__ . '/../vendor/autoload.php';
Auth::startSession();
if (!Auth::hasDatabase()) {
    header('Location: /dashboard/onboarding.php');
    exit;
}
// Where to go after signing in, such as the OAuth consent screen. Only local paths are accepted.
$next = (string) ($_GET['next'] ?? '');
if (!preg_match('#^/(?![/\\\\])[^\x00-\x20\\\\]*$#', $next)) {
    $next = '/dashboard/';
}
if (Auth::user()) {
    header('Location: ' . $next);
    exit;
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Csrf::isValid()) {
    $error = 'This page expired. Please try again.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    try {
        Auth::engine()->login($email, $password);
        header('Location: ' . $next);
        exit;
    } catch (TooManyRequestsException) {
        http_response_code(429);
        $error = 'Too many sign-in attempts. Please try again later.';
    } catch (AuthException) {
        $error = 'Incorrect email address or password.';
    }
}
echo View::render('auth/login', ['title' => 'Sign in | Petitstats', 'error' => $error], 'layouts/auth');
