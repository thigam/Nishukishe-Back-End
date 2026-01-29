<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'order_reference')) {
                $table->string('order_reference')->nullable()->after('provider_reference');
            }
            if (! Schema::hasColumn('payments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('order_reference');
            }
            if (! Schema::hasColumn('payments', 'channel')) {
                $table->string('channel')->nullable()->after('payment_reference');
            }
            if (! Schema::hasColumn('payments', 'equity_account_number')) {
                $table->string('equity_account_number')->nullable()->after('channel');
            }
            if (! Schema::hasColumn('payments', 'payment_link')) {
                $table->string('payment_link')->nullable()->after('equity_account_number');
            }
            if (! Schema::hasColumn('payments', 'receipt_number')) {
                $table->string('receipt_number')->nullable()->after('payment_link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach ([
                'receipt_number',
                'payment_link',
                'equity_account_number',
                'channel',
                'payment_reference',
                'order_reference',
            ] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
