<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_actions')) {
            return;
        }

        Schema::create('operational_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('pools')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo');
            $table->timestamp('registado_em');
            $table->json('dados')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('foto')->nullable();
            $table->timestamps();

            $table->index(['pool_id', 'registado_em']);
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_actions');
    }
};
