<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_scans', function (Blueprint $table) {
            $table->id();
            $table->json('roots');
            $table->string('status', 16)->default('queued');
            $table->unsignedBigInteger('files_count')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedBigInteger('inaccessible_directories')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('inventory_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_scan_id')->constrained()->cascadeOnDelete();
            $table->text('path');
            $table->string('name');
            $table->string('extension')->default('');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('created_at_timestamp');
            $table->unsignedBigInteger('modified_at_timestamp');
            $table->timestamps();

            $table->index(['inventory_scan_id', 'extension']);
            $table->index(['inventory_scan_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_files');
        Schema::dropIfExists('inventory_scans');
    }
};
