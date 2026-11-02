<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->json('restorable_quality')->nullable()->after('deferred_time_trial');
            $table->string('override_kind')->nullable()->after('restorable_quality');
            $table->date('override_date')->nullable()->after('override_kind');
            $table->dateTime('overridden_at')->nullable()->after('override_date');
        });
    }

    public function down(): void
    {
        Schema::table('plan_adaptations', function (Blueprint $table): void {
            $table->dropColumn(['restorable_quality', 'override_kind', 'override_date', 'overridden_at']);
        });
    }
};
