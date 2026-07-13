<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:set-admin {userId : The user id to grant/revoke admin} {--unset : Revoke admin instead of granting}')]
#[Description('Grant (or with --unset revoke) the maintainer `is_admin` flag on a user. The only way to set this non-fillable privilege.')]
class UserSetAdminCommand extends Command
{
    public function handle(): int
    {
        $userId = (int) $this->argument('userId');
        $user = User::query()->find($userId);

        if ($user === null) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        $grant = ! $this->option('unset');

        // Bypass mass assignment on purpose: is_admin is guarded, so it is set
        // directly here rather than via update([...]).
        $user->is_admin = $grant;
        $user->save();

        $verb = $grant ? 'granted admin to' : 'revoked admin from';
        $this->info("Successfully {$verb} {$user->name} <{$user->email}> (id {$userId}).");

        return self::SUCCESS;
    }
}
