<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create archive table with same schema as daily_records
        if (!Schema::hasTable('daily_records_archive')) {
            Schema::create('daily_records_archive', function (Blueprint $table) {
                // Original fields from daily_records
                $table->id();
                $table->unsignedBigInteger('pool_id');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('registado_em');

                // Água / Análises Gerais
                $table->decimal('ph', 4, 2)->nullable();
                $table->decimal('cloro_total', 4, 2)->nullable();
                $table->decimal('cloro_livre', 4, 2)->nullable();
                $table->decimal('cloro_combinado', 4, 2)->nullable();
                $table->decimal('alcalinidade', 5, 2)->nullable();
                $table->decimal('temperatura_agua', 4, 2)->nullable();
                $table->integer('turbidez')->nullable();
                $table->text('observacoes')->nullable();

                // Bomba
                $table->enum('bomba_funcionamento', ['on', 'off', 'desconhecido'])->default('desconhecido');
                $table->integer('bomba_duracao_minutos')->nullable();
                $table->text('bomba_observacoes')->nullable();
                $table->string('bomba_foto')->nullable();

                // Filtros
                $table->enum('filtro_estado', ['limpo', 'sujo', 'entupido', 'desconhecido'])->default('desconhecido');
                $table->integer('filtro_retrolavagem_duracao')->nullable();
                $table->text('filtro_observacoes')->nullable();

                // Contador
                $table->bigInteger('contador_leitura')->nullable();
                $table->string('contador_foto')->nullable();

                // Tanque
                $table->enum('tanque_nivel', ['cheio', 'normal', 'baixo', 'critico', 'desconhecido'])->default('desconhecido');
                $table->text('tanque_observacoes')->nullable();
                $table->string('tanque_foto')->nullable();

                // Nossas Análises (NS)
                $table->decimal('ns_cloro_teste_rapido', 4, 2)->nullable();
                $table->decimal('ns_ph_teste_rapido', 4, 2)->nullable();
                $table->text('ns_observacoes')->nullable();

                // Água (Ciclo de Vida)
                $table->enum('agua_modo', ['on_com_agua', 'on_sem_agua', 'off', 'desconhecido'])->default('desconhecido');

                // Correlação e Correções (Append-Only)
                $table->boolean('e_correcao')->default(false);
                $table->unsignedBigInteger('corrige_registo_id')->nullable();
                $table->text('razao_correcao')->nullable();

                // Metadata
                $table->timestamps();
                $table->timestamp('archived_at')->nullable()->default(now());

                // Foreign Keys
                $table->foreign('pool_id')->references('id')->on('pools')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('corrige_registo_id')->references('id')->on('daily_records_archive')->onDelete('set null');

                // Indexes for archive queries
                $table->index(['pool_id', 'registado_em']);
                $table->index('e_correcao');
                $table->index('archived_at');
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_records_archive');
    }
};
