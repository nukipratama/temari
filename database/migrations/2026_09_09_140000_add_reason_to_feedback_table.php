<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $firstPerSubject = DB::table('feedback')
            ->selectRaw('MIN(id) as id')
            ->groupBy('user_id', 'subject_type', 'subject_id')
            ->pluck('id')
            ->all();

        if ($firstPerSubject !== []) {
            DB::table('feedback')->whereNotIn('id', $firstPerSubject)->delete();
        }

        Schema::table('feedback', function (Blueprint $table): void {
            $table->string('reason', 32)->nullable()->after('subject_id');
            $table->unique(['user_id', 'subject_type', 'subject_id'], 'feedback_one_per_subject_unq');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table): void {
            $table->dropUnique('feedback_one_per_subject_unq');
            $table->dropColumn('reason');
        });
    }
};
