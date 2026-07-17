<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('timer_pushes')) {
            return;
        }

        Schema::create('timer_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pool_id')->nullable();
            $table->string('fase');
            $table->timestamp('fire_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'cancelled_at', 'fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timer_pushes');
    }
};
