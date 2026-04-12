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
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('driver_id')->nullable()->change();
            $table->unsignedBigInteger('vehicle_id')->nullable()->change();
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->string('type')->change();
            $table->integer('upvotes')->default(0)->after('description');
            $table->integer('downvotes')->default(0)->after('upvotes');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'upvotes', 'downvotes']);
        });
    }
};
