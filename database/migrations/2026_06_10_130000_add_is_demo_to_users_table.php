<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Marks the seeded demo account so scheduled work (strava:sync/ingest,
        // the AI recaps) can skip it — the demo uses fake Strava tokens and
        // pre-backfilled narration, so it must never spend a real Strava call or
        // an LLM token. Not a global scope: the demo stays fully visible in-app.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('email');
        });

        // Flag the demo account that already exists from a prior seed: the column
        // defaults to false, and the seeder's firstOrCreate won't update a row it
        // matches, so without this backfill the existing demo user would keep
        // getting billed until a full re-seed. Literal mirrors
        // DemoRunSeeder::DEMO_USER_EMAIL (kept inline so this migration is
        // self-contained and frozen in time).
        DB::table('users')->where('email', 'demo@teman-lari.local')->update(['is_demo' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_demo');
        });
    }
};
