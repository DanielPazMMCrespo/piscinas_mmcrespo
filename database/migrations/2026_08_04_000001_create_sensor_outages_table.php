<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sensor_outages')) {
            return;
        }

        Schema::create('sensor_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools')->cascadeOnDelete();

            // Guardado por valor (não FK): o dispositivo pode ser reassociado ou
            // apagado do mapeamento e a avaria histórica tem de continuar legível.
            $table->string('hanna_device_id')->nullable();

            $table->string('motivo');
            $table->text('detalhe')->nullable();

            $table->timestamp('aberta_em');
            $table->timestamp('resolvida_em')->nullable();

            $table->foreignId('aberta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolvida_por')->nullable()->constrained('users')->nullOnDelete();

            // Ação operacional que reportou / deu baixa da avaria.
            $table->foreignId('opened_action_id')->nullable()->constrained('operational_actions')->nullOnDelete();
            $table->foreignId('resolved_action_id')->nullable()->constrained('operational_actions')->nullOnDelete();

            $table->timestamps();

            $table->index(['pool_id', 'resolvida_em']);
            $table->index(['pool_id', 'aberta_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_outages');
    }
};
