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
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('scan_id')
                ->constrained('audit_scans')
                ->cascadeOnDelete()
                ->index();
            $table->enum('finding_type', ['sast', 'sca']);
            $table->string('issue_type', 100);
            $table->enum('severity', [
                'critical',
                'high',
                'medium',
                'low',
                'info',
                'unknown'
            ]);
            $table->text('description');
            $table->string('file_path', 500);
            $table->unsignedInteger('line_number')->nullable();
            $table->string('rule_id')->nullable();
            $table->string('package_name')->nullable();
            $table->string('current_version')->nullable();
            $table->string('cve_id', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
