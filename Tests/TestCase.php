<?php

namespace Modules\LJPcCalendarModule\Tests;

use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase as FreeScoutTestCase;

abstract class TestCase extends FreeScoutTestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDefaultConnection() !== 'testing') {
            $this->fail('Module tests must run on the "testing" connection.');
        }
    }

    protected function makeUser(array $attributes = []): User
    {
        return factory(User::class)->create($attributes + [
            'role'     => User::ROLE_USER,
            'status'   => User::STATUS_ACTIVE,
            'type'     => User::TYPE_USER,
            'timezone' => 'Europe/Amsterdam',
        ]);
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return $this->makeUser($attributes + ['role' => User::ROLE_ADMIN]);
    }

    protected function makeMailbox(array $userIds = []): Mailbox
    {
        $mailbox = factory(Mailbox::class)->create();
        if ($userIds) {
            $mailbox->users()->attach($userIds);
        }

        return $mailbox;
    }

    /** A Teams-module team: a deleted robot user whose email starts with "fsteam" and whose emails JSON lists member ids. */
    protected function makeTeam(array $memberIds): User
    {
        return factory(User::class)->create([
            'role'   => User::ROLE_USER,
            'status' => User::STATUS_DELETED,
            'type'   => User::TYPE_ROBOT,
            'email'  => 'fsteam' . uniqid() . '@example.com',
            'emails' => json_encode(array_values($memberIds)),
        ]);
    }
}
