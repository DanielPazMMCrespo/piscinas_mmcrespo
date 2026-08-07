<?php

namespace App\Livewire\Mobile;

use Livewire\Component;

class DailyLog extends Component
{
    public $ph;
    public $free_chlorine;
    public $total_chlorine;
    public $temperature;
    public $showMore = false;
    
    public $alkalinity;
    public $cyanuric_acid;
    public $water_meter;

    public function save()
    {
        $this->validate([
            'ph' => 'nullable|numeric',
            'free_chlorine' => 'nullable|numeric',
            'total_chlorine' => 'nullable|numeric',
            'temperature' => 'nullable|numeric',
        ]);
        
        // Em um cenário real, gravaríamos no modelo DailyRecord
        
        session()->flash('success', 'Registo guardado.');
        
        // Reset campos após gravar (ou redirecionar)
        $this->reset(['ph', 'free_chlorine', 'total_chlorine', 'temperature', 'alkalinity', 'cyanuric_acid', 'water_meter']);
    }
    
    public function toggleMore()
    {
        $this->showMore = !$this->showMore;
    }

    public function render()
    {
        return view('livewire.mobile.daily-log')->layout('components.layouts.mobile');
    }
}
