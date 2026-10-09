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
        Schema::create('audit_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('ip_address', 45)
                ->nullable()
                ->index();
            $table->enum('source_type', ['zip', 'git'])
                ->default('zip');
            $table->string('repo_name');
            $table->enum('status', [
                'queued',
                'processing',
                'completed',
                'failed',
            ])
                ->default('queued')
                ->index();
            $table->unsignedTinyInteger('health_score')
                ->nullable();
            $table->timestamp('completed_at')
                ->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_scans');
    }
};
