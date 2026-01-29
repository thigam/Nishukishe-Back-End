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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('refund_status')->nullable()->after('status');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_status');
            $table->text('refund_reason')->nullable()->after('refund_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_amount', 'refund_reason']);
        });
    }
};
