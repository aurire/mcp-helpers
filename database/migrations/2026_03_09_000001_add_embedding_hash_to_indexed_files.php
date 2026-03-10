<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->string('embedding_hash', 64)
                ->nullable()
                ->after('file_hash')
                ->comment('xxh3 hash tracking the last embedded version of this file');
        });
    }

    public function down(): void
    {
        Schema::table('indexed_files', function (Blueprint $table) {
            $table->dropColumn('embedding_hash');
        });
    }
};
