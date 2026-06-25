<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Restaura 4 tabelas apagadas por erro na migração drop_unused_tables:
// - notifications  : Filament usa para sino de notificações (não estava vazia — estava no estado inicial)
// - filter_checks  : FilterCheckResource existe e precisa desta tabela
// - stock_installation_logs : CreateDailyRecord::descontarStock() cria logs aqui
// - stock_warehouse_logs    : StockWarehouseResource cria logs aqui
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('filter_checks')) {
            Schema::create('filter_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pool_id')->constrained();
                $table->foreignId('user_id')->constrained();
                $table->timestamp('verificado_em');
                $table->enum('tipo_operacao', ['lavagem', 'enxaguamento', 'posicao_normal']);
                $table->string('caminho_foto')->nullable();
                $table->text('observacoes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_installation_logs')) {
            Schema::create('stock_installation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_installation_id')->constrained('stock_installations')->restrictOnDelete();
                $table->foreignId('user_id')->constrained();
                $table->enum('tipo_movimento', ['entrada', 'consumo']);
                $table->decimal('quantity', 10, 3);
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('stock_warehouse_logs')) {
            Schema::create('stock_warehouse_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained();
                $table->foreignId('user_id')->constrained();
                $table->enum('tipo_movimento', ['entrada', 'saida']);
                $table->decimal('quantity', 10, 3);
                $table->string('fornecedor')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_installation_logs');
        Schema::dropIfExists('stock_warehouse_logs');
        Schema::dropIfExists('filter_checks');
        Schema::dropIfExists('notifications');
    }
};
