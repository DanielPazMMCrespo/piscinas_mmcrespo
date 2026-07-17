<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_broadcasts')) {
            return;
        }

        Schema::create('custom_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('titulo');
            $table->text('corpo');
            $table->json('cargos');
            $table->string('tipo_agendamento'); // 'unico' | 'diario'
            $table->timestamp('enviar_em')->nullable();
            $table->string('hora_diaria')->nullable(); // 'HH:MM'
            $table->boolean('ativo')->default(true);
            $table->date('ultima_data_enviada')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_broadcasts');
    }
};
