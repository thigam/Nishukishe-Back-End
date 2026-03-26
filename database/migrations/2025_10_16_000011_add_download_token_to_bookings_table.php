<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('download_token', 64)
                ->nullable()
                ->after('reference')
                ->unique();
        });

        DB::table('bookings')
            ->whereNull('download_token')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($booking): void {
                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update(['download_token' => Str::random(40)]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('download_token');
        });
    }
};
