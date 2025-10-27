<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->string('key')->index();
            $table->string('category')->nullable()->index();
            $table->string('memory_type')->nullable()->index();
            $table->longText('value_markdown');
            $table->json('metadata')->nullable();
            $table->integer('importance')->default(5)->index();
            $table->enum('status', ['active', 'archived', 'resolved'])->default('active')->index();
            $table->timestamps();
            
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
