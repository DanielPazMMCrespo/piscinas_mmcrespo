<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tokens de convite passam a ser armazenados como hash SHA-256 (sempre 64 chars hex).
 * CHAR(64) é mais eficiente que VARCHAR(64) para comprimento fixo.
 *
 * Só relevante em PostgreSQL (produção): SQLite tem tipagem dinâmica e ignora a
 * distinção CHAR/VARCHAR, pelo que a alteração é um no-op em dev. Evita-se também
 * o uso de ->change()->unique() (que em SQLite reconstrói a tabela e tenta recriar
 * o índice único já existente).
 *
 * Os convites pendentes (token plaintext) ficam inválidos após o deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE user_invitations ALTER COLUMN token TYPE char(64)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE user_invitations ALTER COLUMN token TYPE varchar(64)');
    }
};
