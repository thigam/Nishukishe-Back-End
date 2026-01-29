<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_clean_trips', function (Blueprint $table) {
            if (Schema::hasColumn('pre_clean_trips', 'pre_clean_sacco_route_id')) {
                $table->dropForeign(['pre_clean_sacco_route_id']);
                $table->dropColumn('pre_clean_sacco_route_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pre_clean_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('pre_clean_trips', 'pre_clean_sacco_route_id')) {
                $table->unsignedBigInteger('pre_clean_sacco_route_id')->nullable()->after('id');
                $table->foreign('pre_clean_sacco_route_id')
                      ->references('id')
                      ->on('pre_clean_sacco_routes')
                      ->cascadeOnDelete();
            }
        });
    }
};

