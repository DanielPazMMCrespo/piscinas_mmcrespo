<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pool_closure_tasks')) {
            return;
        }

        Schema::create('pool_closure_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_closure_id')->constrained('pool_closures')->cascadeOnDelete();

            $table->string('tipo', 40);
            $table->unsignedSmallInteger('ordem');
            $table->boolean('obrigatorio')->default(true);

            $table->string('estado', 20)->default('previsto');
            $table->string('origem', 20)->default('declarada');

            $table->date('previsto_para')->nullable();
            $table->timestamp('executado_em')->nullable();
            $table->foreignId('executado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->text('motivo_nao_execucao')->nullable();

            $table->json('dados')->nullable();
            $table->json('fotos')->nullable();
            $table->json('documentos')->nullable();
            $table->text('observacoes')->nullable();

            $table->timestamps();

            $table->index(['pool_closure_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pool_closure_tasks');
    }
};
