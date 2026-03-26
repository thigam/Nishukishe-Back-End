<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bookable_id')->constrained('bookables')->cascadeOnDelete();
            $table->string('payout_type');
            $table->boolean('is_primary')->default(true);
            $table->string('phone_number')->nullable();
            $table->string('till_number')->nullable();
            $table->string('paybill_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['bookable_id', 'payout_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_profiles');
    }
};
