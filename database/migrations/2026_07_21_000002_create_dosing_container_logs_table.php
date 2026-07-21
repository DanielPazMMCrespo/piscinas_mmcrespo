<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trilho de auditoria dos bidões: cada consumo automático (dosagem do
     * controlador), reabastecimento e ajuste manual fica registado. Permite
     * reconciliar o nível estimado com a realidade e corrigir sem perder rasto.
     */
    public function up(): void
    {
        Schema::create('dosing_container_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dosing_container_id')->constrained()->cascadeOnDelete();
            $table->string('tipo_movimento', 20); // 'consumo' | 'reabastecimento' | 'ajuste'
            $table->decimal('quantidade_ml', 10, 2); // negativo = saída, positivo = entrada
            $table->decimal('restante_apos_ml', 10, 2);
            $table->string('origem', 20)->default('controlador'); // 'controlador' | 'manual'
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nota')->nullable();
            $table->timestamp('registado_em');
            $table->timestamps();

            $table->index(['dosing_container_id', 'registado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dosing_container_logs');
    }
};
