<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->string('intent_verdict')->nullable()->after('distance_score');
            // text, not json: a native JSON column re-orders object keys on
            // round-trip, which would make Eloquent's array-cast dirty-check
            // see a "change" on every write even when the value is identical,
            // permanently breaking `wasChanged()` for a re-graded, unmoved day.
            $table->text('intent_evidence')->nullable()->after('intent_verdict');
        });
    }

    public function down(): void
    {
        Schema::table('planned_sessions', function (Blueprint $table): void {
            $table->dropColumn(['intent_verdict', 'intent_evidence']);
        });
    }
};
