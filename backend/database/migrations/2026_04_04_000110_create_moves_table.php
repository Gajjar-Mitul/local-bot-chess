<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ply');
            $table->string('player_color', 5);
            $table->string('from_square', 2);
            $table->string('to_square', 2);
            $table->string('promotion', 1)->nullable();
            $table->string('uci', 5);
            $table->string('san', 20)->nullable();
            $table->text('fen_after');
            $table->timestamps();

            $table->index(['game_id', 'ply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moves');
    }
};
