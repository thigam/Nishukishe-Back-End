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
    // If the table already exists (e.g., created manually or by an earlier dump),
    // skip creation so this migration can complete without error.
    if (Schema::hasTable('payments')) {
        return;
    }

    Schema::create('payments', function (Blueprint $table) {	   
	    $table->id();
        $table->string('phone');                        // 2547XXXXXXXX
        $table->integer('amount');
        $table->string('account_reference')->nullable();
        $table->string('description')->nullable();
        $table->string('merchant_request_id')->nullable();
        $table->string('checkout_request_id')->nullable()->index();
        $table->string('status')->default('PENDING');   // PENDING, SUCCESS, FAILED
        $table->json('raw_callback')->nullable();
        $table->string('mpesa_receipt_number')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
