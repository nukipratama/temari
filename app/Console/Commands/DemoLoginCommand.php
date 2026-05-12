<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

use function in_array;

#[Signature('demo:login')]
#[Description('Print a signed URL that logs the demo user in (local env only).')]
class DemoLoginCommand extends Command
{
    public function handle(): int
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true)) {
            $this->error('demo:login is only available in the local environment.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->first();
        if ($user === null) {
            $this->error('Demo user not found. Run `php artisan demo:seed --fresh` first.');

            return self::FAILURE;
        }

        // absolute: false signs only the path + query so the URL works on
        // whatever host the user is hitting (localhost, *.test, sail's
        // port-forward) — the 24h expiry + APP_KEY signature still gate it.
        $url = URL::temporarySignedRoute(
            'demo.login',
            now()->addDay(),
            ['user' => $user->id],
            absolute: false,
        );

        $this->line('');
        $this->info('One-tap demo login (valid 24h):');
        $this->line($url);
        $this->line('');

        return self::SUCCESS;
    }
}
