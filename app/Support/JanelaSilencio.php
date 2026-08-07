<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonInterface;

/**
 * Janela de silêncio das notificações. Dentro dela a aplicação não empurra
 * nada para o dispositivo nem para o e-mail: nem alertas de conformidade,
 * nem resumos, nem avisos operacionais.
 *
 * Padrão: das 22:00 às 08:00 todos os dias, e ao domingo o dia inteiro.
 *
 * Os avisos que só disparam uma vez por episódio (torneira aberta, bidão
 * baixo, pH em overtime, escalação de incidente) consultam esta classe antes
 * de marcar o episódio como notificado — caso contrário o silêncio não adiava
 * o aviso, apagava-o para sempre.
 */
class JanelaSilencio
{
    public const INICIO_PADRAO = '22:00';

    public const FIM_PADRAO = '08:00';

    public function __construct(private readonly SettingsService $settings) {}

    public function ativa(?CarbonInterface $momento = null): bool
    {
        if (! $this->settings->getBool('silencio_ativo', true)) {
            return false;
        }

        $momento ??= Carbon::now();

        if ($momento->isSunday() && $this->settings->getBool('silencio_domingo', true)) {
            return true;
        }

        $inicio = $this->hora('silencio_inicio', self::INICIO_PADRAO);
        $fim = $this->hora('silencio_fim', self::FIM_PADRAO);

        if ($inicio === $fim) {
            return false;
        }

        $agora = $momento->format('H:i');

        // Uma janela 22:00 -> 08:00 atravessa a meia-noite: aí o intervalo é a
        // união das duas pontas do dia, não a interseção.
        return $inicio > $fim
            ? ($agora >= $inicio || $agora < $fim)
            : ($agora >= $inicio && $agora < $fim);
    }

    /**
     * Descrição legível da janela, para helperText e mensagens.
     */
    public function descricao(): string
    {
        if (! $this->settings->getBool('silencio_ativo', true)) {
            return 'Desativada.';
        }

        $texto = 'Das '.$this->hora('silencio_inicio', self::INICIO_PADRAO)
            .' às '.$this->hora('silencio_fim', self::FIM_PADRAO);

        return $this->settings->getBool('silencio_domingo', true)
            ? $texto.', e ao domingo o dia inteiro.'
            : $texto.'.';
    }

    private function hora(string $key, string $default): string
    {
        $valor = $this->settings->get($key, $default);

        return is_string($valor) && preg_match('/^\d{2}:\d{2}$/', $valor) === 1
            ? $valor
            : $default;
    }
}
