<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('path');
            $table->char('path_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('conditions');
            $table->string('logic', 8)->default('and');
            $table->string('action', 16);
            $table->text('target')->nullable();
            $table->string('schedule', 16)->nullable();
            $table->timestamp('last_scheduled_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->index(['is_enabled', 'priority']);
        });

        Schema::create('file_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watched_folder_id')->constrained()->cascadeOnDelete();
            $table->char('path_hash', 64);
            $table->text('file_path');
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('modified_at');
            $table->unsignedTinyInteger('stable_scans')->default(1);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['watched_folder_id', 'path_hash']);
        });

        Schema::create('file_executions', function (Blueprint $table) {
            $table->id();
            $table->char('unique_key', 64)->unique();
            $table->foreignId('watched_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->text('file_path');
            $table->unsignedBigInteger('modified_at');
            $table->char('checksum', 64);
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->uuid('sweep_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_uuid')->unique();
            $table->foreignId('file_execution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('sweep_id')->nullable()->index();
            $table->string('action', 16);
            $table->text('source_path');
            $table->text('destination_path')->nullable();
            $table->char('checksum', 64);
            $table->char('result_checksum', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['sweep_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('file_executions');
        Schema::dropIfExists('file_observations');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('watched_folders');
    }
};
