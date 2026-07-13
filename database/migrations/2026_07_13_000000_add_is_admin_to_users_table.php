<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Per-Strava-account maintainer flag: gates the ops dashboards
        // (/ai-usage, /horizon, /pulse) and is the alert target for maintainer
        // push notifications. Never mass-assignable (kept out of User's
        // Fillable): granted only via the `user:set-admin` command.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });
    }
};
