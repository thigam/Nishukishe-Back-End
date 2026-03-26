<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tembea_operator_profiles', 'slug')) {
            Schema::table('tembea_operator_profiles', function (Blueprint $table): void {
                $table->string('slug')->nullable()->after('company_name');
            });

            $this->backfillSlugs();

            Schema::table('tembea_operator_profiles', function (Blueprint $table): void {
                $table->unique('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tembea_operator_profiles', 'slug')) {
            Schema::table('tembea_operator_profiles', function (Blueprint $table): void {
                $table->dropUnique(['slug']);
                $table->dropColumn('slug');
            });
        }
    }

    protected function backfillSlugs(): void
    {
        $existing = [];

        DB::table('tembea_operator_profiles')
            ->select(['id', 'company_name'])
            ->chunkById(100, function ($profiles) use (&$existing): void {
                foreach ($profiles as $profile) {
                    $base = Str::slug((string) ($profile->company_name ?? 'tembea-operator'));

                    if ($base === '') {
                        $base = 'tembea-operator';
                    }

                    $slug = $base;
                    $suffix = 2;

                    while (in_array($slug, $existing, true) || $this->slugExists($slug)) {
                        $slug = $base.'-'.$suffix;
                        $suffix++;
                    }

                    $existing[] = $slug;

                    DB::table('tembea_operator_profiles')
                        ->where('id', $profile->id)
                        ->update(['slug' => $slug]);
                }
            });
    }

    protected function slugExists(string $slug): bool
    {
        return DB::table('tembea_operator_profiles')
            ->where('slug', $slug)
            ->exists();
    }
};
