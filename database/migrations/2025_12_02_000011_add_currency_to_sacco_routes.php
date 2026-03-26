<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'pre_clean_sacco_routes',
        'post_clean_sacco_routes',
        'sacco_routes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'currency')) {
                    $table->string('currency', 3)
                        ->default('KES')
                        ->after('off_peak_fare');
                }
            });

            DB::table($tableName)
                ->whereNull('currency')
                ->update(['currency' => 'KES']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'currency')) {
                    $table->dropColumn('currency');
                }
            });
        }
    }
};
