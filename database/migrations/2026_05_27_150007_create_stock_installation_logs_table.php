<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_installation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_installation_id')->constrained('stock_installations');
            $table->foreignId('user_id')->constrained();
            $table->enum('tipo_movimento', ['entrada', 'consumo']);
            $table->decimal('quantity', 10, 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_installation_logs');
    }
};
