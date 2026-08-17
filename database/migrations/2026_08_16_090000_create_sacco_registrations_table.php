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
        Schema::create('sacco_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_name');
            $table->string('registration_number')->nullable();
            $table->string('website_link')->nullable();
            $table->string('social_media_link')->nullable();
            $table->json('official_contacts')->nullable(); // Array of [{ phone, label }]
            $table->json('routes')->nullable(); // Array of [{ route_name, route_number, stops }]
            $table->string('contact_person_name');
            $table->string('contact_person_email');
            $table->string('contact_person_phone');
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_registrations');
    }
};
