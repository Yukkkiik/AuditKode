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
        Schema::create('ai_refactorings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vulnerability_id')
                ->constrained('vulnerabilities')
                ->cascadeOnDelete();
            $table->text('original_code');
            $table->text('suggested_code')->nullable();
            $table->text('explanation')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->boolean('fallback_mode')->default(false);   
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_refactorings');
    }
};
