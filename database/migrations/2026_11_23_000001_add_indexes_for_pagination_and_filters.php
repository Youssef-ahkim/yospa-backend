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
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['spa_id', 'status'], 'reservations_spa_id_status_index');
            $table->index(['spa_id', 'client_id'], 'reservations_spa_id_client_id_index');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index(['spa_id', 'full_name'], 'clients_spa_id_full_name_index');
        });

        Schema::table('therapists', function (Blueprint $table) {
            $table->index(['spa_id', 'is_active'], 'therapists_spa_id_is_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_spa_id_status_index');
            $table->dropIndex('reservations_spa_id_client_id_index');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_spa_id_full_name_index');
        });

        Schema::table('therapists', function (Blueprint $table) {
            $table->dropIndex('therapists_spa_id_is_active_index');
        });
    }
};
