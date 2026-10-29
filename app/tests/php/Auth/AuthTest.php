<?php

declare(strict_types=1);

namespace Petitstats\Tests\Auth;

use Delight\Auth\UserAlreadyExistsException;
use Petitstats\Auth\Auth;
use Petitstats\Auth\Invitations;
use Petitstats\Auth\McpTokens;
use Petitstats\Auth\Users;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private const PASSWORD = 'Test-Passw0rd!';

    #[DataProvider('passwords')]
    public function testPasswordRules(string $password, bool $accepted): void
    {
        $this->assertSame($accepted, Auth::passwordError($password) === null);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function passwords(): iterable
    {
        yield 'strong' => [self::PASSWORD, true];
        yield 'too short' => ['Ab1!', false];
        yield 'no uppercase' => ['test-passw0rd!', false];
        yield 'no lowercase' => ['TEST-PASSW0RD!', false];
        yield 'no digit' => ['Test-Password!', false];
        yield 'no symbol' => ['TestPassw0rd1', false];
    }

    public function testEmailValidation(): void
    {
        $this->assertTrue(Auth::validEmail('owner@petitstats.test'));
        $this->assertFalse(Auth::validEmail('not-an-email'));
    }

    public function testCreatesAdminsAndMembers(): void
    {
        $admin = Auth::createUser('admin-' . uniqid() . '@petitstats.test', self::PASSWORD, 'admin');
        $member = Auth::createUser('member-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');

        $this->assertTrue($this->isAdmin($admin));
        $this->assertFalse($this->isAdmin($member));
    }

    public function testRefusesADuplicateEmail(): void
    {
        $email = 'twice-' . uniqid() . '@petitstats.test';
        Auth::createUser($email, self::PASSWORD, 'member');

        $this->expectException(UserAlreadyExistsException::class);
        Auth::createUser($email, self::PASSWORD, 'member');
    }

    public function testAnMcpTokenAuthenticatesItsOwner(): void
    {
        $email = 'mcp-' . uniqid() . '@petitstats.test';
        $userId = Auth::createUser($email, self::PASSWORD, 'member');
        $token = McpTokens::create($userId, 'Claude');

        $this->assertSame(['id' => $userId, 'email' => $email, 'can_write' => false], McpTokens::authenticate($token['token']));
        $this->assertNull(McpTokens::authenticate('mlt_not-a-real-token'));
    }

    public function testListedTokensNeverExposeTheirValue(): void
    {
        $userId = Auth::createUser('list-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');
        $token = McpTokens::create($userId, 'Cursor');

        $listed = McpTokens::forUser($userId);
        $this->assertCount(1, $listed);
        $this->assertArrayNotHasKey('token', $listed[0]);
        $this->assertStringNotContainsString($token['token'], (string) json_encode($listed));
    }

    public function testOnlyTheOwnerCanRevokeAToken(): void
    {
        $owner = Auth::createUser('owner-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');
        $other = Auth::createUser('other-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');
        $token = McpTokens::create($owner, 'Codex');

        $this->assertFalse(McpTokens::revoke($other, $token['id']));
        $this->assertNotNull(McpTokens::authenticate($token['token']));

        $this->assertTrue(McpTokens::revoke($owner, $token['id']));
        $this->assertNull(McpTokens::authenticate($token['token']));
    }

    public function testOnlyTheOwnerCanLetATokenEditFunnels(): void
    {
        $owner = Auth::createUser('writer-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');
        $other = Auth::createUser('intruder-' . uniqid() . '@petitstats.test', self::PASSWORD, 'member');
        $token = McpTokens::create($owner, 'Claude Code');

        $this->assertFalse(McpTokens::allowWrite($other, $token['id'], true));
        $this->assertFalse(McpTokens::authenticate($token['token'])['can_write'] ?? null);

        $this->assertTrue(McpTokens::allowWrite($owner, $token['id'], true));
        $this->assertTrue(McpTokens::authenticate($token['token'])['can_write'] ?? null);
        $this->assertTrue(McpTokens::forUser($owner)[0]['can_write']);

        $this->assertTrue(McpTokens::allowWrite($owner, $token['id'], false));
        $this->assertFalse(McpTokens::authenticate($token['token'])['can_write'] ?? null);
    }

    public function testAnInvitationCanBeAcceptedOnce(): void
    {
        $email = 'invited-' . uniqid() . '@petitstats.test';
        $invitation = Invitations::create($email, Auth::createUser('inviter-' . uniqid() . '@petitstats.test', self::PASSWORD, 'admin'));

        $pending = Invitations::findPending($invitation['token']);
        $this->assertSame($email, $pending['email'] ?? null);

        Invitations::markAccepted($pending['id']);
        $this->assertNull(Invitations::findPending($invitation['token']));
    }

    public function testANewInvitationReplacesThePendingOne(): void
    {
        $email = 'reinvited-' . uniqid() . '@petitstats.test';
        $admin = Auth::createUser('admin-' . uniqid() . '@petitstats.test', self::PASSWORD, 'admin');
        $first = Invitations::create($email, $admin);
        $second = Invitations::create($email, $admin);

        $this->assertNull(Invitations::findPending($first['token']));
        $this->assertNotNull(Invitations::findPending($second['token']));
        $this->assertNull(Invitations::findPending('not-a-token'));
    }

    public function testUsersLookups(): void
    {
        $email = 'lookup-' . uniqid() . '@petitstats.test';
        $id = Auth::createUser($email, self::PASSWORD, 'member');
        $admins = Users::adminCount();
        Auth::createUser('extra-admin-' . uniqid() . '@petitstats.test', self::PASSWORD, 'admin');

        $this->assertSame($email, Users::find($id)['email'] ?? null);
        $this->assertNull(Users::find(PHP_INT_MAX));
        $this->assertTrue(Users::emailExists($email));
        $this->assertFalse(Users::emailExists('nobody-' . uniqid() . '@petitstats.test'));
        $this->assertSame($admins + 1, Users::adminCount());
        $this->assertContains($id, array_column(Users::all(), 'id'));
    }

    private function isAdmin(int $userId): bool
    {
        return Users::find($userId)['role'] === 'admin';
    }
}
