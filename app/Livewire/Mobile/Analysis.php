<?php

namespace App\Livewire\Mobile;

use Livewire\Component;

class Analysis extends Component
{
    public function render()
    {
        // Dummy data for the last 7 days
        $days = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
        $phData = [7.2, 7.3, 7.4, 7.2, 7.1, 7.3, 7.4];
        $chlorineData = [1.5, 1.4, 1.2, 1.8, 2.0, 1.6, 1.5];

        return view('livewire.mobile.analysis', [
            'labels' => $days,
            'phData' => $phData,
            'chlorineData' => $chlorineData,
        ])->layout('components.layouts.mobile');
    }
}
