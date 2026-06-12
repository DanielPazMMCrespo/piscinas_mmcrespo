<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Duração real de cada fase da retrolavagem (segundos). ---
        // O técnico escolhe os minutos, o timer arranca e pode terminar antes;
        // guardamos o tempo efetivo de cada fase para o livro sanitário.
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'lavagem_segundos')) {
                $table->unsignedInteger('lavagem_segundos')->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'enxaguamento_segundos')) {
                $table->unsignedInteger('enxaguamento_segundos')->nullable();
            }
        });

        // --- Alertas de "água aberta" (torneira deixada em 'ligada'). ---
        // Uma linha por piscina enquanto estiver por resolver. Resolve-se quando
        // um registo seguinte fecha a torneira, ou pela página rápida da notificação.
        Schema::create('tap_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_record_id')->nullable()->constrained('daily_records')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_record_id')->nullable()->constrained('daily_records')->nullOnDelete();
            // 'registo_seguinte' | 'pagina_rapida' — como foi fechado.
            $table->string('resolution', 30)->nullable();
            $table->timestamps();

            // Consulta quente: "há alerta por resolver para esta piscina?"
            $table->index(['pool_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tap_alerts');

        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn(['lavagem_segundos', 'enxaguamento_segundos']);
        });
    }
};
