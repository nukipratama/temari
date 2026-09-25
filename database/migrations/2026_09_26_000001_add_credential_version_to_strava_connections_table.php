<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('strava_connections', function (Blueprint $table): void {
            $table->unsignedInteger('credential_version')->default(0)->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('strava_connections', function (Blueprint $table): void {
            $table->dropColumn('credential_version');
        });
    }
};
