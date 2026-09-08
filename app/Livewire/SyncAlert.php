<?php

namespace App\Livewire;

use App\Calendar\ResyncDispatcher;
use App\Calendar\SyncHealthReporter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class SyncAlert extends Component
{
    public function render(): View
    {
        return view('livewire.sync-alert', ['health' => app(SyncHealthReporter::class)->current()]);
    }

    #[On('sync-health-changed')]
    public function refreshHealth(): void
    {
        // No-op: render() re-resolves the health on every request; this
        // listener only exists so a state change dispatched by SyncStatus
        // triggers a re-render of this component too.
    }

    public function retry(): void
    {
        app(ResyncDispatcher::class)->dispatch();
    }
}
