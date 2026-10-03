<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Support\PulseMigration;

return new class () extends PulseMigration {
    /** @contract-migration */
    public function up(): void
    {
        if (! $this->shouldRun() || ! in_array($this->driver(), ['mariadb', 'mysql'], true)) {
            return;
        }

        $connection = DB::connection($this->getConnection());

        foreach (['pulse_values', 'pulse_entries', 'pulse_aggregates'] as $table) {
            $expression = (string) $connection->scalar(
                'SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'key_hash'],
            );

            if (! str_contains(strtolower($expression), 'md5')) {
                continue;
            }

            $connection->statement(
                "ALTER TABLE `{$table}` MODIFY `key_hash` BINARY(16) GENERATED ALWAYS AS (unhex(left(sha2(`key`, 256), 32))) VIRTUAL, ALGORITHM=COPY, LOCK=SHARED",
            );
        }
    }

    public function down(): void
    {
    }
};
