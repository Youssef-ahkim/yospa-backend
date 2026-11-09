<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('spa_id')->nullable()->constrained('spas')->onDelete('cascade');
            $table->string('role')->default('owner');
        });
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['spa_id']);
            $table->dropColumn(['spa_id', 'role']);
        });
    }
};
