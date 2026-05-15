<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            // 'fcm' = native Capacitor app (Android/iOS WebView)
            // 'web_push' = browser (desktop/mobile web)
            $table->string('token_type', 20)->default('fcm')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn('token_type');
        });
    }
};
