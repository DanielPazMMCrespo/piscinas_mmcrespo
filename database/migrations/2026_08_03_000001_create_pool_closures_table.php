<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pool_closures')) {
            return;
        }

        Schema::create('pool_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools')->cascadeOnDelete();

            // Granularidade ao dia: a conformidade, os registos diários e o livro
            // sanitário são todos diários. 'fim' é o último dia encerrado
            // (inclusivo); null = encerramento em aberto.
            $table->date('inicio');
            $table->date('fim')->nullable();

            $table->string('motivo');
            $table->boolean('agua_em_tratamento')->default(false);
            $table->text('observacoes')->nullable();

            $table->foreignId('encerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reaberta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reaberta_em')->nullable();

            $table->timestamps();

            $table->index(['pool_id', 'inicio']);
            $table->index(['pool_id', 'fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pool_closures');
    }
};
