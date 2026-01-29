<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tour_events', function (Blueprint $table) {
            // First drop the index on destination since we are changing its type
            $table->dropIndex(['destination']);

            // Change columns to json (or text/longtext if json is not supported directly, but json is standard in Laravel)
            // We use DB::statement for changing type if standard change() doesn't work well with data preservation,
            // but Laravel's change() usually works. However, changing string to json might fail if data isn't valid json.
            // Since existing data is just strings, we might need to be careful.
            // A safer approach for migration with data:
            // 1. Rename old columns
            // 2. Create new json columns
            // 3. Migrate data
            // 4. Drop old columns

            // Let's try the direct change first, assuming we can cast or it handles it. 
            // Actually, casting string "Nairobi" to JSON might result in error or invalid JSON.
            // It's safer to do the rename-create-migrate-drop dance.
        });

        // Step 1: Rename
        Schema::table('tour_events', function (Blueprint $table) {
            $table->renameColumn('destination', 'destination_old');
            $table->renameColumn('meeting_point', 'meeting_point_old');
        });

        // Step 2: Create new
        Schema::table('tour_events', function (Blueprint $table) {
            $table->json('destination')->nullable()->after('bookable_id');
            $table->json('meeting_point')->nullable()->after('destination');
        });

        // Step 3: Migrate data
        // We will wrap the old string value into our new structure: [{ "name": "Old Value", "display_name": "Old Value" }]
        // This is a raw query.
        DB::table('tour_events')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $dest = [];
                if (!empty($row->destination_old)) {
                    $dest[] = [
                        'name' => $row->destination_old,
                        'display_name' => $row->destination_old,
                        // We don't have coords for old data, so we leave them out or null
                        'coordinates' => null
                    ];
                }

                $meet = [];
                if (!empty($row->meeting_point_old)) {
                    $meet[] = [
                        'name' => $row->meeting_point_old,
                        'display_name' => $row->meeting_point_old,
                        'coordinates' => null
                    ];
                }

                DB::table('tour_events')
                    ->where('id', $row->id)
                    ->update([
                            'destination' => json_encode($dest),
                            'meeting_point' => json_encode($meet)
                        ]);
            }
        });

        // Step 4: Drop old
        Schema::table('tour_events', function (Blueprint $table) {
            $table->dropColumn('destination_old');
            $table->dropColumn('meeting_point_old');
        });
    }

    public function down(): void
    {
        // To revert, we pick the first location's name
        Schema::table('tour_events', function (Blueprint $table) {
            $table->string('destination_old')->nullable();
            $table->string('meeting_point_old')->nullable();
        });

        DB::table('tour_events')->orderBy('id')->chunk(100, function ($rows) {
            foreach ($rows as $row) {
                $dest = json_decode($row->destination, true);
                $destName = $dest[0]['name'] ?? '';

                $meet = json_decode($row->meeting_point, true);
                $meetName = $meet[0]['name'] ?? '';

                DB::table('tour_events')
                    ->where('id', $row->id)
                    ->update([
                            'destination_old' => $destName,
                            'meeting_point_old' => $meetName
                        ]);
            }
        });

        Schema::table('tour_events', function (Blueprint $table) {
            $table->dropColumn('destination');
            $table->dropColumn('meeting_point');
        });

        Schema::table('tour_events', function (Blueprint $table) {
            $table->renameColumn('destination_old', 'destination');
            $table->renameColumn('meeting_point_old', 'meeting_point');
            $table->index('destination');
        });
    }
};
