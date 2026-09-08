<?php

namespace App\Livewire;

use App\Calendar\ResyncDispatcher;
use App\Calendar\SyncHealthReporter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SyncStatus extends Component
{
    public ?string $lastState = null;

    public function render(): View
    {
        $health = app(SyncHealthReporter::class)->current();

        if ($this->lastState !== null && $this->lastState !== $health->state->value) {
            $this->dispatch('sync-health-changed');
        }

        $this->lastState = $health->state->value;

        return view('livewire.sync-status', ['health' => $health]);
    }

    public function resync(): void
    {
        app(ResyncDispatcher::class)->dispatch();
    }
}
