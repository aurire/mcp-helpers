<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->index()->constrained('memories')->onDelete('cascade');
            $table->string('user_id')->index();
            $table->string('tag')->index();
            $table->timestamp('created_at')->useCurrent();
            
            $table->unique(['memory_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_tags');
    }
};
