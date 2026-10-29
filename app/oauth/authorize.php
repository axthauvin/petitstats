<?php

declare(strict_types=1);

use Petitstats\Auth\Auth;
use Petitstats\Auth\Csrf;
use Petitstats\Http\View;
use Petitstats\OAuth\OAuthException;
use Petitstats\OAuth\OAuthServer;

/**
 * OAuth consent screen: the signed-in user allows an AI assistant to read the
 * analytics, and the assistant receives an authorization code.
 */
require_once __DIR__ . '/../vendor/autoload.php';
Auth::startSession();
if (!Auth::hasDatabase()) {
    header('Location: /dashboard/onboarding.php');
    exit;
}

const OAUTH_PARAMS = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

$redirect = static function (string $location): never {
    header('Location: ' . $location, true, 302);
    exit;
};
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$params = array_intersect_key($isPost ? $_POST : $_GET, array_flip(OAUTH_PARAMS));
$error = null;
$request = null;

try {
    $request = OAuthServer::authorizationRequest($params);
} catch (OAuthException $e) {
    if ($e->redirectUri !== null) {
        $redirect(OAuthServer::redirectWith($e->redirectUri, ['error' => $e->error, 'error_description' => $e->getMessage(), 'state' => $e->state]));
    }
    $error = $e->getMessage();
}

$user = Auth::user();
if ($request !== null && $user === null) {
    $redirect('/dashboard/login.php?next=' . rawurlencode('/oauth/authorize.php?' . http_build_query($params)));
}

if ($request !== null && $isPost) {
    if (!Csrf::isValid()) {
        $error = 'This page expired. Go back to your assistant and connect again.';
    } else {
        if (($_POST['decision'] ?? '') === 'allow') {
            $code = OAuthServer::createAuthorizationCode($request, (int) $user['id']);
            $redirect(OAuthServer::redirectWith($request['redirect_uri'], ['code' => $code, 'state' => $request['state']]));
        }
        $redirect(OAuthServer::redirectWith($request['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'Access was denied.', 'state' => $request['state']]));
    }
}

echo View::render('auth/oauth-authorize', [
    'title' => 'Connect an assistant | Petitstats',
    'error' => $error,
    'params' => $params,
    'clientName' => $request['client']['client_name'] ?? '',
    'email' => $user['email'] ?? '',
    'redirectHost' => $request === null ? '' : (string) (parse_url($request['redirect_uri'], PHP_URL_HOST) ?: parse_url($request['redirect_uri'], PHP_URL_SCHEME)),
    'isLocalApp' => $request !== null && OAuthServer::redirectsToLocalApp($request['redirect_uri']),
], 'layouts/auth');
