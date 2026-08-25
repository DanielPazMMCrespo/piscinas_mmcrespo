<?php

namespace App\Livewire\Mobile;

use Livewire\Component;
use Livewire\WithFileUploads;

class ReportIncident extends Component
{
    use WithFileUploads;

    public $type = null;

    public $description = '';

    public $photo;

    public $showDateEditor = false;

    public $occurred_at;

    public function mount()
    {
        $this->occurred_at = now()->format('Y-m-d\TH:i');
    }

    public function selectType($type)
    {
        $this->type = $type;
    }

    public function toggleDateEditor()
    {
        $this->showDateEditor = ! $this->showDateEditor;
    }

    public function save()
    {
        $this->validate([
            'type' => 'required|string',
            'description' => 'required|string|min:3',
            'photo' => 'nullable|image|max:10240', // 10MB
        ]);

        // Simula guardar

        session()->flash('success', 'Incidente reportado com sucesso.');
        $this->reset(['type', 'description', 'photo']);
        $this->occurred_at = now()->format('Y-m-d\TH:i');
        $this->showDateEditor = false;
    }

    public function render()
    {
        return view('livewire.mobile.report-incident')->layout('components.layouts.mobile');
    }
}
