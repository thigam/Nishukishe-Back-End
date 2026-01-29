<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        if (Schema::hasColumn('trips', 'peak_status')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->dropColumn('peak_status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        if (! Schema::hasColumn('trips', 'peak_status')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->string('peak_status')->nullable();
            });
        }
    }
};
