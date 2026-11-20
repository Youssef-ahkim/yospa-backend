<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Re-check for duplicates before applying unique constraint
        $duplicates = DB::table('services')
            ->select('spa_id', 'name', DB::raw('COUNT(*) as count'))
            ->groupBy('spa_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add unique index to services: duplicate service names exist within the same spa: ' . $duplicates->toJson()
            );
        }

        Schema::table('services', function (Blueprint $table) {
            $table->unique(['spa_id', 'name'], 'services_spa_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique('services_spa_id_name_unique');
        });
    }
};
