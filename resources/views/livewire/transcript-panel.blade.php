<?php

use App\Transcripts\TranscriptLinkSource;
use App\Transcripts\TranscriptState;

?>
<section class="kb-panel" aria-labelledby="transcript-h">
    <div class="kb-panel__head">
        <div>
            <p class="kb-owned-flag">{{ __('Yours') }}</p>
            <h2 id="transcript-h">{{ __('Transcript') }}</h2>
        </div>
        <div class="kb-panel__actions">
            @if ($state === TranscriptState::Linked)
                <span class="kb-tag @if ($linkSource === TranscriptLinkSource::Convention) kb-tag--transcript @else kb-tag--ok @endif">
                    {{ $linkSource === TranscriptLinkSource::Convention ? __('Linked by convention') : __('Linked manually') }}
                </span>
                <span role="status" aria-live="polite">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        x-data="kbCopy({ source: 'url', url: @js($sourceUrl) })"
                        x-on:click="copy"
                        x-text="state === 'copied' ? @js(__('Copied')) : (state === 'error' ? @js(__('Copy failed')) : @js(__('Copy as Markdown')))"
                    >{{ __('Copy as Markdown') }}</flux:button>
                </span>
                <flux:button size="sm" variant="ghost" :href="$printUrl" target="_blank" rel="noopener">{{ __('Print / Save as PDF') }}</flux:button>
                <flux:modal.trigger name="transcript-picker">
                    <flux:button size="sm" variant="outline">{{ __('Relink…') }}</flux:button>
                </flux:modal.trigger>
                <flux:button size="sm" variant="ghost" wire:click="clearLink">{{ __('Clear link') }}</flux:button>
            @elseif ($state === TranscriptState::Broken || $state === TranscriptState::Unreadable || $state === TranscriptState::Rejected)
                <flux:modal.trigger name="transcript-picker">
                    <flux:button size="sm" variant="outline">{{ __('Relink…') }}</flux:button>
                </flux:modal.trigger>
                <flux:button size="sm" variant="ghost" wire:click="clearLink">{{ __('Clear link') }}</flux:button>
            @elseif ($state === TranscriptState::Suppressed)
                <flux:button size="sm" variant="outline" wire:click="useConvention">{{ __('Use the convention again') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="kb-panel__body">
        @if ($state === TranscriptState::NotConfigured)
            <div class="kb-empty">
                <h3>{{ __('Transcripts directory not configured') }}</h3>
                <p>{{ __('Set :env in .env and re-run :cmd. A configuration prompt — not an error page.', ['env' => 'KBMS_TRANSCRIPTS_PATH', 'cmd' => 'php artisan kbms:doctor']) }}</p>
            </div>
        @elseif ($state === TranscriptState::Missing)
            <p class="kb-note-inline">{{ __('No transcript found for this meeting.') }}</p>
            <flux:modal.trigger name="transcript-picker">
                <flux:button size="sm" variant="outline">{{ __('Browse for a file…') }}</flux:button>
            </flux:modal.trigger>
        @elseif ($state === TranscriptState::Ambiguous)
            <div class="kb-stack">
                <div class="kb-inline">
                    <span class="kb-tag kb-tag--warn">{{ __('Choose one') }}</span>
                    <span class="kb-note-inline">{{ __('The resolver does not guess — it surfaces the matches within tolerance.') }}</span>
                </div>
                <div class="kb-stack">
                    @foreach ($candidates as $candidate)
                        <label class="kb-inline kb-candidate">
                            <input
                                type="radio"
                                name="transcript-candidate"
                                value="{{ $candidate->filename }}"
                                wire:model="selectedCandidate"
                                @checked($loop->first && $selectedCandidate === '')
                            >
                            <code>{{ $candidate->filename }}</code>
                            <span class="kb-note-inline">
                                @if ($candidate->driftMinutes === 0)
                                    {{ __('exact minute') }}
                                @else
                                    {{ $candidate->driftMinutes > 0 ? '+'.$candidate->driftMinutes : $candidate->driftMinutes }} {{ __('min') }}
                                @endif
                                &middot; {{ number_format($candidate->bytes / 1024, 1) }} KB
                            </span>
                        </label>
                    @endforeach
                </div>
                <div class="kb-inline">
                    <flux:button size="sm" variant="primary" wire:click="linkSelected">{{ __('Link selected') }}</flux:button>
                    <flux:modal.trigger name="transcript-picker">
                        <flux:button size="sm" variant="ghost">{{ __('Browse for another file…') }}</flux:button>
                    </flux:modal.trigger>
                </div>
            </div>
        @elseif ($state === TranscriptState::Broken)
            <div class="kb-banner" role="alert">
                <span aria-hidden="true">&#9888;</span>
                <span>
                    <strong>{{ __('Linked file is gone') }}</strong>
                    <code>{{ $path }}</code> {{ __('no longer exists. The link is kept so nothing is lost silently.') }}
                </span>
            </div>
        @elseif ($state === TranscriptState::Unreadable)
            <div class="kb-banner" role="alert">
                <span aria-hidden="true">&#9888;</span>
                <span>
                    <strong>{{ __('File exists but is not readable') }}</strong>
                    <code>{{ $path }}</code> {{ __('is not readable. This is reported distinctly from a missing file, because the fix is different: chmod, not relink.') }}
                </span>
            </div>
        @elseif ($state === TranscriptState::Rejected)
            <div class="kb-banner" role="alert">
                <span aria-hidden="true">&#9888;</span>
                <span>
                    <strong>{{ __('Rejected') }}</strong>
                    {{ __('The linked path escapes the configured transcripts directory and is refused outright, whether or not the file exists.') }}
                </span>
            </div>
        @elseif ($state === TranscriptState::Suppressed)
            <p class="kb-note-inline">{{ __('The convention will not re-link this meeting.') }}</p>
        @elseif ($state === TranscriptState::Linked)
            <p class="kb-path">
                <code>{{ $path }}</code>
                <button
                    type="button"
                    class="kb-iconbtn"
                    aria-label="{{ __('Copy transcript path') }}"
                    onclick="navigator.clipboard.writeText(@js($path))"
                >&#10697;</button>
            </p>
        @endif
    </div>

    @if ($state === TranscriptState::Linked)
        <div class="kb-scroll @if (! $content->isMarkdown) kb-scroll--mono @else kb-md @endif" tabindex="0" role="region" aria-label="{{ __('Transcript preview, scrollable') }}">
            {!! $content->rendered !!}
        </div>

        @if ($content->truncated)
            <p class="kb-truncation">{{ __('showing first :size — open the file for the rest', ['size' => $this->formatBytes(config('kbms.transcript_preview_bytes'))]) }}</p>
        @endif

        <div class="kb-panel__foot">
            <span>{{ $sizeLabel }} &middot; {{ __('modified :when', ['when' => $modifiedLabel]) }} &middot; {{ $content->isMarkdown ? __('.md rendered as Markdown') : __('.txt rendered as plain text') }}</span>
            <span class="kb-panel__foot__end">{{ __('Read on demand — never copied into the database') }}</span>
        </div>
    @endif

    @if (in_array($state, [TranscriptState::Missing, TranscriptState::Ambiguous, TranscriptState::Linked, TranscriptState::Broken, TranscriptState::Unreadable, TranscriptState::Rejected], true))
        <flux:modal name="transcript-picker" class="max-w-lg">
            <div class="kb-stack">
                <h3 class="kb-modal__title">{{ __('Browse transcripts') }}</h3>
                <flux:input wire:model.live="filter" placeholder="{{ __('Filter by filename…') }}" />
                <div class="kb-stack">
                    @forelse ($pickerFiles as $file)
                        <flux:modal.close>
                            <button type="button" class="kb-candidate" wire:click="link(@js($file))">
                                <code>{{ $file }}</code>
                            </button>
                        </flux:modal.close>
                    @empty
                        <p class="kb-note-inline">{{ __('No files match.') }}</p>
                    @endforelse
                </div>
            </div>
        </flux:modal>
    @endif
</section>
