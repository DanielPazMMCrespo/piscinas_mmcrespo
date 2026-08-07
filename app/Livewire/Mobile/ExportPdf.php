<?php

namespace App\Livewire\Mobile;

use Livewire\Component;

class ExportPdf extends Component
{
    public $period;

    public function mount()
    {
        // Define o mês atual por defeito
        $this->period = now()->format('Y-m');
    }

    public function render()
    {
        return view('livewire.mobile.export-pdf')->layout('components.layouts.mobile');
    }
}
