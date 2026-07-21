<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bidões de reagente do controlador de cada piscina (cloro e pH-).
     * O nível (restante_ml) é descontado automaticamente a partir do volume
     * doseado que o controlador Hanna reporta (campo DV do deviceLogHistory).
     * Um par por piscina; a unicidade (pool_id, tipo) garante isso.
     */
    public function up(): void
    {
        Schema::create('dosing_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 20); // 'cloro' | 'ph_menos'
            $table->unsignedInteger('capacidade_ml')->nullable();
            $table->decimal('restante_ml', 10, 2)->default(0);
            $table->unsignedTinyInteger('alerta_percent')->default(20);
            $table->timestamp('reabastecido_em')->nullable();
            $table->foreignId('reabastecido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('alerta_notificado_em')->nullable();
            $table->timestamps();

            $table->unique(['pool_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dosing_containers');
    }
};
