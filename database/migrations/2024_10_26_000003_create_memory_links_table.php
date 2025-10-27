<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_links', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->foreignId('source_memory_id')->index()->constrained('memories')->onDelete('cascade');
            $table->foreignId('target_memory_id')->index()->constrained('memories')->onDelete('cascade');
            $table->string('relationship_type')->index();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->unique(['source_memory_id', 'target_memory_id', 'relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_links');
    }
};
