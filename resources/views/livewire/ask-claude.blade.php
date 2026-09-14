<?php

use App\Launcher\PromptLaunchStatus;

?>
<section
    class="kb-panel"
    aria-labelledby="ask-h"
    @if ($latest && $latest->status === PromptLaunchStatus::Queued) wire:poll.1s @endif
>
    <div class="kb-panel__head">
        <div>
            <p class="kb-owned-flag">{{ __('Yours') }}</p>
            <h2 id="ask-h">{{ __('Ask Claude Code') }}</h2>
        </div>
        <div class="kb-panel__actions">
            <span class="kb-tag @if ($command) kb-tag--ok @else kb-tag--muted @endif">
                {{ $command ? __('Launcher ready') : __('Blocked') }}
            </span>
        </div>
    </div>

    <div class="kb-panel__body">
        {{--
            x-data lives on this wrapper, not on .sk-field alone, because the
            chord hint below renders inside the sibling .kb-inline row — both
            need the same kbLaunchShortcut instance (US-019 TASK-04).
        --}}
        <div
            x-data="kbLaunchShortcut({
                max: @js((int) config('kbms.question_max_chars')),
                labels: @js(\App\Launcher\LaunchChord::labels()),
                spokenLabels: @js(\App\Launcher\LaunchChord::spokenLabels()),
                spokenTemplate: @js(__('Press :chord to launch the session.')),
            })"
        >
            <div class="sk-field" style="margin-bottom:0.75rem">
                <label for="question">{{ __('Your question') }}</label>
                <textarea
                    class="kb-textarea kb-textarea--sm"
                    id="question"
                    wire:model.live.debounce.400ms="question"
                    placeholder="{{ __('What should I ask about this meeting?') }}"
                    aria-describedby="question-help @if ($command) question-shortcut @endif @if ($isQuestionBlock || $errors->has('question')) question-error @endif"
                    @if ($isQuestionBlock || $errors->has('question')) aria-invalid="true" @endif
                    x-on:keydown.cmd.enter.prevent="submit($event)"
                    x-on:keydown.ctrl.enter.prevent="submit($event)"
                ></textarea>
                <p class="kb-note-inline" id="question-help" style="margin:0.375rem 0 0">
                    {!! __('Handed to your script as the second argument, exactly as typed &mdash; quotes, newlines and <code>$(&hellip;)</code> included, never interpreted.') !!}
                </p>
                @if ($errors->has('question'))
                    <p id="question-error" role="alert" style="margin:0.375rem 0 0;font-size:0.75rem;color:var(--kb-danger)">
                        {{ $errors->first('question') }}
                    </p>
                @elseif ($isQuestionBlock)
                    <p id="question-error" role="alert" style="margin:0.375rem 0 0;font-size:0.75rem;color:var(--kb-danger)">
                        {{ $blockMessage }}
                    </p>
                @endif
            </div>

            <div class="kb-inline">
                <button
                    type="button"
                    class="sk-btn sk-btn--primary"
                    wire:click="launch"
                    @if (! $command) disabled aria-describedby="ask-block-reason" @endif
                >
                    <span aria-hidden="true">&#9654;</span> {{ __('Launch session') }}
                </button>
                @if ($command)
                    <span class="kb-tag kb-tag--muted" aria-hidden="true" x-text="chordLabel"></span>
                    <span class="kb-note-inline">{{ __('to launch') }}</span>
                    <span id="question-shortcut" class="sr-only" x-text="spokenHint"></span>
                    <span class="kb-note-inline">{{ __('Queued · opens its own terminal window · replies stay in the terminal') }}</span>
                @elseif (! $isQuestionBlock)
                    <span class="kb-note-inline" id="ask-block-reason">{{ $blockMessage }}</span>
                @endif
            </div>
        </div>

        @if ($command)
            <p class="kb-note-inline" style="margin:1rem 0 0.375rem">{{ __('Exact invocation') }}</p>
            <pre class="kb-command" aria-label="{{ __('Exact command that will be run') }}">{{ $commandLines[0] }}
<span class="kb-arg">{{ $commandLines[1] }}</span>
<span class="kb-arg kb-arg--2">{{ $commandLines[2] }}</span></pre>
            <div class="kb-inline" style="margin-top:0.5rem">
                <button
                    type="button"
                    class="sk-btn sk-btn--outline"
                    onclick="navigator.clipboard.writeText(@js($command->display()))"
                >{{ __('Copy invocation') }}</button>
                <span class="kb-note-inline">{{ __('Two arguments, in order: context file, then question.') }}</span>
            </div>
        @endif

        @if ($latest)
            <div class="kb-inline" style="margin-top:1rem">
                @if ($latest->status === PromptLaunchStatus::Queued)
                    <span class="kb-tag kb-tag--ok">{{ __('Dispatched') }}</span>
                    <span class="kb-note-inline">{{ __("Queued job accepted. The terminal window is owned by the OS: quitting queue:work does not close the operator's session.") }}</span>
                    @if ($stillQueued)
                        <span class="kb-note-inline">{{ __('still queued — is :cmd running?', ['cmd' => 'php artisan queue:work']) }}</span>
                    @endif
                @elseif ($latest->status === PromptLaunchStatus::Failed)
                    <div class="kb-banner" role="alert">
                        <span aria-hidden="true">&#9888;</span>
                        <span>
                            <strong>{{ __('Launcher exited :code', ['code' => $latest->exit_code]) }}</strong>
                            {{ __("Recorded on the launch row and shown here. Copy the invocation below and run it in a terminal to see the script's own output.") }}
                        </span>
                    </div>
                @elseif ($latest->status === PromptLaunchStatus::TimedOut)
                    <div class="kb-banner" role="alert">
                        <span aria-hidden="true">&#9888;</span>
                        <span>{{ $latest->error }}</span>
                    </div>
                @elseif ($latest->status === PromptLaunchStatus::Blocked)
                    <div class="kb-banner" role="alert">
                        <span aria-hidden="true">&#9888;</span>
                        <span>{{ $latest->error }}</span>
                    </div>
                @elseif ($latest->status === PromptLaunchStatus::Launched)
                    <span class="kb-tag kb-tag--ok">{{ __('Launched') }}</span>
                @endif
            </div>
        @endif
    </div>

    <div class="kb-panel__foot">
        <span>{{ __("The application never captures Claude's reply — the meeting record holds the question, not the answer.") }}</span>
    </div>
</section>
