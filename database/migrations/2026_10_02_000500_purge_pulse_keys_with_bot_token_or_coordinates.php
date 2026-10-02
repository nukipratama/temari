<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Support\PulseMigration;

return new class () extends PulseMigration {
    /** @contract-migration */
    public function up(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        foreach (['pulse_entries', 'pulse_aggregates'] as $table) {
            DB::connection($this->getConnection())
                ->table($table)
                ->where(fn (Builder $query) => $query
                    ->where('key', 'like', '%api.telegram.org%/bot%')
                    ->orWhere('key', 'like', '%lat=%')
                    ->orWhere('key', 'like', '%latitude=%')
                    ->orWhere('key', 'like', '%verify_token=%'))
                ->delete();
        }
    }

    public function down(): void
    {
    }
};
