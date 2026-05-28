<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('verificado_em');
            $table->enum('tipo_operacao', ['lavagem', 'enxaguamento', 'posicao_normal']);
            $table->string('caminho_foto')->nullable();
            $table->enum('resultado_ia', ['correto', 'incorreto', 'nao_determinado'])->nullable();
            $table->text('descricao_ia')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_checks');
    }
};
