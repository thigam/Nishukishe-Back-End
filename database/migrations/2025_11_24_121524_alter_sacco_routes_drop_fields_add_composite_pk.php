<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('sacco_routes', function (Blueprint $table) {
                foreach (['route_number', 'route_start_stop', 'route_end_stop'] as $column) {
                    if (Schema::hasColumn('sacco_routes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });

            return;
        }

        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->dropColumn(['route_number', 'route_start_stop', 'route_end_stop']);
        });
    }

    public function down() {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('sacco_routes', function (Blueprint $table) {
                foreach (['route_number', 'route_start_stop', 'route_end_stop'] as $column) {
                    if (! Schema::hasColumn('sacco_routes', $column)) {
                        $table->string($column)->nullable();
                    }
                }
            });

            return;
        }

        Schema::table('sacco_routes', function (Blueprint $table) {
            $table->string('route_number');
            $table->string('route_start_stop');
            $table->string('route_end_stop');

            $table->dropForeign(['route_id']);
            $table->dropPrimary(['sacco_id','route_id']);
            $table->primary('route_id');
        });
    }
};

