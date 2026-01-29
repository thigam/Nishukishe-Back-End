<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->decimal('requested_amount', 12, 2)->nullable()->after('net_amount');
            $table->timestamp('requested_at')->nullable()->after('requested_amount');
            $table->json('requested_by')->nullable()->after('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table): void {
            $table->dropColumn(['requested_by', 'requested_at', 'requested_amount']);
        });
    }
};
