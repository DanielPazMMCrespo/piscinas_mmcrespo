<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca até que instante a dosagem do controlador já foi descontada dos
     * bidões. Evita contar duas vezes o mesmo volume entre sincronizações.
     */
    public function up(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('hanna_devices', 'dose_sincronizada_ate')) {
                $table->timestamp('dose_sincronizada_ate')->nullable()->after('raw_info');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            if (Schema::hasColumn('hanna_devices', 'dose_sincronizada_ate')) {
                $table->dropColumn('dose_sincronizada_ate');
            }
        });
    }
};
