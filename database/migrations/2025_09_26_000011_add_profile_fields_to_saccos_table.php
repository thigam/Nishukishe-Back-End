<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            $table->string('profile_headline')->nullable()->after('sacco_address');
            $table->text('profile_description')->nullable()->after('profile_headline');
            $table->string('share_slug')->nullable()->unique()->after('profile_description');
            $table->string('profile_contact_name')->nullable()->after('share_slug');
            $table->string('profile_contact_phone')->nullable()->after('profile_contact_name');
            $table->string('profile_contact_email')->nullable()->after('profile_contact_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saccos', function (Blueprint $table) {
            $table->dropUnique('saccos_share_slug_unique');
            $table->dropColumn([
                'profile_headline',
                'profile_description',
                'share_slug',
                'profile_contact_name',
                'profile_contact_phone',
                'profile_contact_email',
            ]);
        });
    }
};
