<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thread de mensagens por incidente: mistura mensagens de chat (tipo=mensagem)
// com mensagens automáticas de mudança de estado (tipo=sistema) na mesma
// timeline, para substituir a comunicação por WhatsApp/telefone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('tipo', 20)->default('mensagem');
            $table->text('texto');
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_messages');
    }
};
