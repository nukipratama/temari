<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOLEAN)) {
            Artisan::call('demo:seed', ['--fresh' => true], $this->command?->getOutput());
        }
    }
}
