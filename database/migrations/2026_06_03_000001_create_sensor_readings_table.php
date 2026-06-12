<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leituras automáticas dos sensores Hanna Cloud (BL132 e similares).
     *
     * Tabela separada dos daily_records intencionalmente:
     *   - Não "polui" o livro sanitário obrigatório (que é manual + validado)
     *   - Permite retenção diferente (bruto 90 dias, agragados sem limite)
     *   - Registo manual MANTÉM-SE como fonte legal; sensor é 2.º par de olhos
     *
     * Nota: ORP (mV) é proxy do poder desinfetante, NÃO é cloro livre (mg/L).
     * Para ter cloro livre automatizado precisa de sensor amperométrico adicional.
     */
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();

            // Pool pode ser null se o dispositivo ainda não estiver mapeado.
            $table->foreignId('pool_id')->nullable()->constrained()->nullOnDelete();

            // Device ID da Hanna Cloud (DID), ex: "BL132-XXXX".
            $table->string('hanna_device_id', 60);

            // Timestamp da leitura segundo a Hanna Cloud (pode diferir do created_at).
            $table->timestamp('lida_em');

            // Parâmetros medidos (todos nullable — nem sempre todos disponíveis).
            $table->decimal('ph', 5, 2)->nullable();
            $table->decimal('orp', 7, 2)->nullable();          // mV
            $table->decimal('temperatura_agua', 5, 1)->nullable(); // °C
            $table->decimal('temperatura_ar', 5, 1)->nullable();   // °C
            $table->decimal('caudal_ph', 8, 2)->nullable();    // mL/h dosagem pH
            $table->decimal('caudal_cloro', 8, 2)->nullable(); // mL/h dosagem cloro

            // JSON completo de parâmetros brutos (para não perder dados não mapeados).
            $table->json('raw_parameters')->nullable();

            $table->timestamps();

            // Índices de consulta frequentes.
            $table->index('hanna_device_id');
            $table->index(['pool_id', 'lida_em']);
        });

        // Mapeamento device Hanna Cloud → piscina (pode mudar se reconfigurado).
        Schema::create('hanna_devices', function (Blueprint $table) {
            $table->id();
            $table->string('hanna_device_id', 60)->unique(); // DID da Hanna Cloud
            $table->string('name');
            $table->foreignId('pool_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->json('raw_info')->nullable(); // info do get_devices()
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
        Schema::dropIfExists('hanna_devices');
    }
};
