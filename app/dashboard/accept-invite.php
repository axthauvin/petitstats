<?php

declare(strict_types=1);

use Delight\Auth\UserAlreadyExistsException;
use Petitstats\Auth\Auth;
use Petitstats\Auth\Csrf;
use Petitstats\Auth\Invitations;
use Petitstats\Http\Asset;
use Petitstats\Http\View;

require_once __DIR__ . '/../vendor/autoload.php';
Auth::startSession();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$invite = Auth::hasDatabase() ? Invitations::findPending($token) : null;
if (!$invite) {
    $error = 'This invitation link is invalid or has expired.';
}
if (!$error && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (!Csrf::isValid()) {
        $error = 'This page expired. Please try again.';
    } elseif ($passwordError = Auth::passwordError($password)) {
        $error = $passwordError;
    } else {
        try {
            $id = Auth::createUser($invite['email'], $password, 'member');
            Invitations::markAccepted($invite['id']);
            Auth::loginById($id);
            header('Location: /dashboard/#websites');
            exit;
        } catch (UserAlreadyExistsException) {
            $error = 'An account already exists for this email address.';
        }
    }
}
echo View::render('auth/accept-invite', [
    'title' => 'Join Petitstats',
    'error' => $error,
    'email' => $invite['email'] ?? '',
    'token' => $token,
    'scripts' => [Asset::url('/dashboard/src/assets/js/password-strength.js')],
], 'layouts/auth');
