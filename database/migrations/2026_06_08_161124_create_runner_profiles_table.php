<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('runner_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_hr')->default(180);
            $table->unsignedSmallInteger('resting_hr')->default(55);
            $table->json('hr_zones');
            $table->unsignedSmallInteger('optimal_cadence_spm')->default(170);
            $table->timestamp('hr_zones_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runner_profiles');
    }
};
