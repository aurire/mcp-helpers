<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', 500)->index();
            $table->string('file_quick_hash', 16)->index();
            $table->string('content_hash', 64)->index()->comment('SHA-256 for deduplication');
            $table->enum('operation_type', ['replace', 'insert', 'delete', 'create', 'restore']);
            $table->mediumText('content')->comment('Full file content');
            $table->unsignedInteger('line_count');
            $table->unsignedInteger('file_size')->comment('Size in bytes');
            $table->json('operation_summary')->nullable()->comment('Details about what changed');
            $table->string('user_id', 100)->nullable()->comment('User identifier if available');
            $table->string('session_id', 100)->nullable()->comment('Session/conversation ID');
            $table->timestamp('created_at')->useCurrent();
            
            // Composite indexes for common queries
            $table->index(['file_path', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
