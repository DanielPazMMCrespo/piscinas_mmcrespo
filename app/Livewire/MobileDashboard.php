<?php

namespace App\Livewire;

use Livewire\Component;

class MobileDashboard extends Component
{
    public function render()
    {
        return view('livewire.mobile-dashboard')->layout('components.layouts.mobile');
    }
}
