<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\DailyRecordTableBuilder;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use Tests\TestCase;

class DailyRecordPhotoXssTest extends TestCase
{
    private function renderFotoEntry(string $maliciousPath): string
    {
        $method = new ReflectionMethod(DailyRecordTableBuilder::class, 'fotoEntry');
        $method->setAccessible(true);

        $entry = $method->invoke(null, 'bomba_foto', 'Foto da Bomba');
        $state = $entry->formatState($maliciousPath);

        return $state instanceof HtmlString ? $state->toHtml() : (string) $state;
    }

    public function test_nome_de_ficheiro_malicioso_nao_injeta_atributo_no_html(): void
    {
        $html = $this->renderFotoEntry("malicioso.jpg' onerror='alert(document.cookie)");

        $this->assertStringNotContainsString('onerror=', $html);
        $this->assertStringContainsString('malicioso.jpg', $html);
    }
}
