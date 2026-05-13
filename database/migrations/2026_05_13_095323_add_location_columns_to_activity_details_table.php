<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            // Strava-sourced run-start coordinates. Filled on sync; used as
            // the input to reverse-geocoding.
            $table->decimal('start_lat', 10, 7)->nullable()->after('summary_polyline');
            $table->decimal('start_lng', 10, 7)->nullable()->after('start_lat');

            // Reverse-geocoded display string ("Kecamatan, Kota, Provinsi,
            // Negara") and ISO-3166 country code. Both null until resolved.
            $table->string('location_name', 200)->nullable()->after('start_lng');
            $table->string('location_country', 2)->nullable()->after('location_name');

            // Flag for the "we tried but found nothing" state, so the
            // resolver doesn't keep retrying. Null → never tried; non-null +
            // location_name null → tried and missed; non-null + name set →
            // resolved successfully.
            $table->timestamp('location_resolved_at')->nullable()->after('location_country');
        });
    }

    public function down(): void
    {
        Schema::table('activity_details', function (Blueprint $table): void {
            $table->dropColumn([
                'start_lat',
                'start_lng',
                'location_name',
                'location_country',
                'location_resolved_at',
            ]);
        });
    }
};
