<?php

declare(strict_types=1);

use Delight\Auth\Role;
use Petitstats\Auth\Auth;
use Petitstats\Auth\Invitations;
use Petitstats\Auth\McpTokens;
use Petitstats\Auth\Users;
use Petitstats\OAuth\OAuthServer;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../vendor/autoload.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Authentication is required for any user operations
Auth::requireLogin();
$currentUser = Auth::user();

try {
    // 1. GET: List all authorized users and include current user info
    if ($method === 'GET') {
        echo json_encode([
            'users' => Users::all(),
            'current_user' => [
                'id' => (int) ($currentUser['id'] ?? 0),
                'email' => $currentUser['email'] ?? '',
                'role' => $currentUser['role'] ?? 'member',
            ],
        ]);
        exit;
    }

    // All modifications (invite, update role, delete user) require admin privileges
    $admin = Auth::requireAdmin();

    $rawInput = file_get_contents('php://input');
    $body = json_decode($rawInput, true) ?: $_POST;
    $action = $body['action'] ?? $_GET['action'] ?? null;

    // 2. DELETE: Remove an authorized user
    if ($method === 'DELETE' || $action === 'delete') {
        $userId = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(400);
            Auth::jsonError('A valid user ID is required.');
        }

        // Prevent self-deletion
        if ($userId === (int) $admin['id']) {
            http_response_code(400);
            Auth::jsonError('You cannot delete your own account.');
        }

        // Check if user exists
        $userToDelete = Users::find($userId);
        if (!$userToDelete) {
            http_response_code(404);
            Auth::jsonError('User not found.');
        }

        // If target is an admin, ensure at least one other administrator remains
        if ($userToDelete['role'] === 'admin') {
            $adminCount = Users::adminCount();
            if ($adminCount <= 1) {
                http_response_code(400);
                Auth::jsonError('Cannot delete the only administrator.');
            }
        }

        // Clean up any invitations created by this user
        Invitations::forgetCreator($userId);
        McpTokens::revokeAllForUser($userId);
        OAuthServer::revokeAllForUser($userId);

        // Delete the user record
        Auth::engine()->admin()->deleteUserById($userId);

        echo json_encode([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
        exit;
    }

    // 3. UPDATE ROLE: Promote or demote user (grant or revoke admin status)
    if ($method === 'PATCH' || $method === 'PUT' || $action === 'update_role' || $action === 'set_role') {
        $userId = (int) ($body['id'] ?? $_GET['id'] ?? 0);
        $newRole = trim(strtolower((string) ($body['role'] ?? '')));

        if ($userId <= 0 || !in_array($newRole, ['admin', 'member'], true)) {
            http_response_code(400);
            Auth::jsonError('A valid user ID and role ("admin" or "member") are required.');
        }

        // Check if user exists
        $targetUser = Users::find($userId);
        if (!$targetUser) {
            http_response_code(404);
            Auth::jsonError('User not found.');
        }

        // Prevent self-demotion from administrator status
        if ($userId === (int) $admin['id'] && $newRole !== 'admin') {
            http_response_code(400);
            Auth::jsonError('You cannot revoke your own administrator status.');
        }

        // If demoting an admin, ensure at least one other administrator remains
        if ($targetUser['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = Users::adminCount();
            if ($adminCount <= 1) {
                http_response_code(400);
                Auth::jsonError('Cannot remove the only administrator.');
            }
        }

        if ($targetUser['role'] !== $newRole) {
            if ($newRole === 'admin') {
                Auth::engine()->admin()->addRoleForUserById($userId, Role::ADMIN);
            } else {
                Auth::engine()->admin()->removeRoleForUserById($userId, Role::ADMIN);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $newRole === 'admin' ? 'User granted administrator status.' : 'Administrator status removed.',
            'role' => $newRole,
        ]);
        exit;
    }

    // 4. POST: Create invitation link (default POST action)
    if ($method === 'POST') {
        $email = trim(strtolower($body['email'] ?? ''));
        if (!Auth::validEmail($email)) {
            http_response_code(400);
            Auth::jsonError('A valid email address is required.');
        }

        if (Users::emailExists($email)) {
            http_response_code(409);
            Auth::jsonError('This email already has an account.');
        }

        $invitation = Invitations::create($email, (int) $admin['id']);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        echo json_encode([
            'success' => true,
            'invite_url' => "$scheme://$host/dashboard/accept-invite.php?token={$invitation['token']}",
            'expires_at' => gmdate('c', $invitation['expires_at']),
        ]);
        exit;
    }

    http_response_code(405);
    Auth::jsonError('Method not allowed.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
