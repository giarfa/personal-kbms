<?php

namespace App\Livewire;

use App\Calendar\SyncHealthReporter;
use App\Meetings\AgendaQuery;
use App\Meetings\AgendaRange;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Agenda')]
class Agenda extends Component
{
    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        $this->date = $this->anchor()->toDateString();
    }

    public function previous(): void
    {
        $this->date = $this->range()->previous()->anchor->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->range()->next()->anchor->toDateString();
    }

    public function today(): void
    {
        $this->date = AgendaRange::today()->anchor->toDateString();
    }

    public function render(): View
    {
        $range = $this->range();

        return view('livewire.agenda', [
            'range' => $range,
            'days' => AgendaQuery::for($range),
            'syncHealth' => app(SyncHealthReporter::class)->current(),
        ]);
    }

    private function range(): AgendaRange
    {
        return new AgendaRange($this->anchor());
    }

    /**
     * The anchor is a #[Url] property, so it is a bookmarkable, hand-editable
     * surface: a truncated or edited value must degrade to today rather than
     * throw a parse error out of render(). AgendaRange normalizes the time
     * component itself.
     */
    private function anchor(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->date);
        } catch (Exception) {
            return AgendaRange::today()->anchor;
        }
    }
}
