<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pool_access_requests')) {
            return;
        }

        Schema::create('pool_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('motivo');
            $table->string('status')->default('pendente');
            $table->text('resposta_admin')->nullable();

            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pool_access_requests');
    }
};
