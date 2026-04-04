<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status')->default('in_progress');
            $table->string('human_color', 5)->default('white');
            $table->string('current_turn', 5)->default('white');
            $table->text('fen');
            $table->unsignedInteger('halfmove_clock')->default(0);
            $table->unsignedInteger('fullmove_number')->default(1);
            $table->string('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
