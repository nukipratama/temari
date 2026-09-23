<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('plan_reconciliation_pending_from')->nullable()->after('plan_recalibration_completed_at');
            $table->date('plan_reconciliation_rebuilding_from')->nullable()->after('plan_reconciliation_pending_from');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['plan_reconciliation_pending_from', 'plan_reconciliation_rebuilding_from']);
        });
    }
};
