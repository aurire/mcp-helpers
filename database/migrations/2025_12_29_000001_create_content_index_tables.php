<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Main content index table
        Schema::create('content_index', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', 500)->index();
            $table->string('token', 100)->index()->comment('Lowercase for search');
            $table->string('original_token', 100)->comment('Original case');
            $table->unsignedInteger('line_number');
            $table->unsignedSmallInteger('token_position')->comment('Position in line');
            $table->string('context', 200)->nullable()->comment('Surrounding 100 chars');
            $table->enum('token_type', [
                'identifier', 
                'method', 
                'class', 
                'string', 
                'variable', 
                'method_call', 
                'namespace'
            ])->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Composite indexes for performance
            $table->index(['token', 'file_path'], 'idx_token_file');
        });

        // File metadata and hash tracking
        Schema::create('indexed_files', function (Blueprint $table) {
            $table->string('file_path', 500)->primary();
            $table->string('file_hash', 64)->index()->comment('xxh3 hash for change detection');
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('file_mtime')->index();
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamp('indexed_at')->useCurrent();
        });

        // Indexing statistics and metadata
        Schema::create('index_metadata', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->useCurrent();
        });

        // Insert initial metadata
        DB::table('index_metadata')->insert([
            ['key' => 'version', 'value' => '1.0', 'updated_at' => now()],
            ['key' => 'total_tokens', 'value' => '0', 'updated_at' => now()],
            ['key' => 'total_files', 'value' => '0', 'updated_at' => now()],
            ['key' => 'last_full_index', 'value' => '', 'updated_at' => now()],
            ['key' => 'index_strategy', 'value' => 'smart_tokens', 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_index');
        Schema::dropIfExists('indexed_files');
        Schema::dropIfExists('index_metadata');
    }
};
