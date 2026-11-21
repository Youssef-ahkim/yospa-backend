<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('spa_id')->nullable()->constrained('spas')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_user_id');
            $table->index('spa_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
