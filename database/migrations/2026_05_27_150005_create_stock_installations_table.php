<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->decimal('limite_minimo', 10, 3)->default(0);
            $table->timestamps();
            $table->unique(['installation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_installations');
    }
};
