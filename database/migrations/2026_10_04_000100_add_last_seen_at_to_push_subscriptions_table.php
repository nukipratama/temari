<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection(config('webpush.database_connection'))->table(config('webpush.table_name'), function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->useCurrent()->after('content_encoding');
        });

        // In the app timezone like every other timestamp, so no existing row is pruned on day one.
        DB::connection(config('webpush.database_connection'))->table(config('webpush.table_name'))->update(['last_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))->table(config('webpush.table_name'), function (Blueprint $table): void {
            $table->dropColumn('last_seen_at');
        });
    }
};
