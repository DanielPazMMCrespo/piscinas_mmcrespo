<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Estado Kanban dos alertas operacionais. Os alertas são calculados (não vivem
// na BD); esta tabela guarda apenas o estado de tratamento de cada um, com uma
// chave estável (ex: "sem_registo|3|2026-06-11"). Linhas antigas são podadas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_states', function (Blueprint $table) {
            $table->id();
            $table->string('alert_key')->unique();
            // pendente | em_curso | resolvido | resolvido_auto
            $table->string('status', 20)->default('pendente');
            // Snapshot do alerta no momento do movimento — permite mostrar
            // cartões resolvidos cuja condição já desapareceu.
            $table->json('payload')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->index(['status', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
    }
};
