<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('race_goals', function (Blueprint $table): void {
            $table->string('outcome', 20)->nullable()->after('completed_at');
            $table->foreignId('outcome_activity_id')->nullable()->after('outcome')->constrained('activities')->nullOnDelete();
            $table->unsignedInteger('finish_time_sec')->nullable()->after('outcome_activity_id');
            $table->timestamp('outcome_recorded_at')->nullable()->after('finish_time_sec');
            $table->index(['outcome', 'race_date']);
        });

        Schema::create('race_goal_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('race_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('race_date')->nullable();
            $table->unsignedInteger('goal_time_sec')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('finish_time_sec')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['race_goal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_goal_changes');

        Schema::table('race_goals', function (Blueprint $table): void {
            $table->dropIndex(['outcome', 'race_date']);
            $table->dropConstrainedForeignId('outcome_activity_id');
            $table->dropColumn(['outcome', 'finish_time_sec', 'outcome_recorded_at']);
        });
    }
};
