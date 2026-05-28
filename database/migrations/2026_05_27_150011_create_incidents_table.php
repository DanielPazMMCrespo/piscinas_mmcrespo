<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('ocorreu_em');
            $table->enum('type', ['avaria_equipamento', 'fuga_agua', 'qualidade_agua', 'outro']);
            $table->text('descricao');
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
