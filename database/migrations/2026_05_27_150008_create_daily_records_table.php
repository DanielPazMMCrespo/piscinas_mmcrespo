<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('registado_em');
            $table->decimal('cloro_livre', 4, 2);
            $table->decimal('cloro_total', 4, 2);
            $table->decimal('ph', 4, 2);
            $table->decimal('temperatura', 4, 1);
            $table->integer('transparencia');
            $table->boolean('caleira_feita')->default(false);
            $table->boolean('renovacao_agua')->default(false);
            $table->text('observacoes')->nullable();
            $table->boolean('e_correcao')->default(false);
            $table->foreignId('corrige_registo_id')
                  ->nullable()
                  ->constrained('daily_records')
                  ->nullOnDelete();
            $table->text('razao_correcao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_records');
    }
};
