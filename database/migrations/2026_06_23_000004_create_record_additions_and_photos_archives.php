<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('record_additions_archive')) {
            Schema::create('record_additions_archive', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_record_id')->constrained('daily_records_archive')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products');
                $table->decimal('quantity', 8, 3);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('record_photos_archive')) {
            Schema::create('record_photos_archive', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_record_id')->constrained('daily_records_archive')->cascadeOnDelete();
                $table->enum('type', ['ns', 'tecnico']);
                $table->string('path');
                $table->json('resultado_ocr')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_photos_archive');
        Schema::dropIfExists('record_additions_archive');
    }
};
