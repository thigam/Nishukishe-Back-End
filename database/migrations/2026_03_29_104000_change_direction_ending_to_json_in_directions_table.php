<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === "pgsql") {
            DB::statement("ALTER TABLE directions ALTER COLUMN direction_ending TYPE json USING direction_ending::json;");
        } else {
            Schema::table("directions", function (Blueprint $table) {
                $table->json("direction_ending")->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === "pgsql") {
            DB::statement("ALTER TABLE directions ALTER COLUMN direction_ending TYPE varchar(255);");
        } else {
            Schema::table("directions", function (Blueprint $table) {
                $table->string("direction_ending")->change();
            });
        }
    }
};
