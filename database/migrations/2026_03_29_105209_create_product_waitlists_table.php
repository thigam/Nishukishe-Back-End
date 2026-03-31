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
        Schema::create('product_waitlists', function (Blueprint $table) {
            $table->id();
            $table->string('sacco_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('product_slug'); // parcels-management, digital-ticketing, onsite-advertising
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sacco_id')->references('sacco_id')->on('saccos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_waitlists');
    }
};
