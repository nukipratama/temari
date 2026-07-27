<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a usage row belonged to, captured at the moment the user is deleted.
 *
 * `ai_token_usages` lives on its own connection, so it has no foreign key to
 * `users` and survives the account being removed. Until now that left the rows
 * attributable only to a bare id whose owner no longer existed, so /ai-usage
 * could show the spend but not whose it was.
 *
 * These are deliberately null for live users: their name and athlete id are
 * resolved from the source tables instead, so a rename is never stale here.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->string('user_name')->nullable()->after('user_id');
            $table->unsignedBigInteger('strava_athlete_id')->nullable()->after('user_name');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('ai_token_usages', function (Blueprint $table): void {
            $table->dropColumn(['user_name', 'strava_athlete_id']);
        });
    }
};
