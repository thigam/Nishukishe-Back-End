<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHUNK_SIZE = 200;

public function up(): void
{
    // Skip this migration entirely if the table doesn't exist (fresh test DB, etc.)
    if (! Schema::hasTable('post_clean_trips')) {
        return;
    }

    $driver = Schema::getConnection()->getDriverName();

    DB::table('post_clean_trips')
        ->select('id', 'route_id')
        ->orderBy('id')
        ->chunkById(self::CHUNK_SIZE, function ($rows) {
            foreach ($rows as $row) {
                if ($row->route_id === null) {
                    continue;
                }

                DB::table('post_clean_trips')
                    ->where('id', $row->id)
                    ->update(['route_id' => (string) $row->route_id]);
            }
        });

    if ($driver === 'sqlite') {
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->text('route_id_string_backup')->nullable();
        });

        DB::table('post_clean_trips')
            ->select('id', 'route_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('post_clean_trips')
                        ->where('id', $row->id)
                        ->update(['route_id_string_backup' => $row->route_id]);
                }
            });

        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->dropColumn('route_id');
        });

        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->renameColumn('route_id_string_backup', 'route_id');
        });
    } else {
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->string('route_id', 191)->change();
        });
    }
}

public function down(): void
{
    // Same guard as in up() — don't touch anything if the table doesn't exist.
    if (! Schema::hasTable('post_clean_trips')) {
        return;
    }

    $driver = Schema::getConnection()->getDriverName();

    DB::table('post_clean_trips')
        ->select('id', 'route_id')
        ->orderBy('id')
        ->chunkById(self::CHUNK_SIZE, function ($rows) {
            foreach ($rows as $row) {
                if ($row->route_id === null) {
                    continue;
                }

                if (is_numeric($row->route_id)) {
                    DB::table('post_clean_trips')
                        ->where('id', $row->id)
                        ->update(['route_id' => (int) $row->route_id]);
                }
            }
        });

    if ($driver === 'sqlite') {
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id_numeric_backup')->nullable();
        });

        DB::table('post_clean_trips')
            ->select('id', 'route_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) {
                foreach ($rows as $row) {
                    if (is_numeric($row->route_id)) {
                        DB::table('post_clean_trips')
                            ->where('id', $row->id)
                            ->update(['route_id_numeric_backup' => (int) $row->route_id]);
                    }
                }
            });

        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->dropColumn('route_id');
        });

        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->renameColumn('route_id_numeric_backup', 'route_id');
        });
    } else {
        Schema::table('post_clean_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->change();
        });
    }
}

};
