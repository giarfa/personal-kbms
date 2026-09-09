<?php

namespace App\Meetings;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The single choke point through which a note body becomes HTML. `Str::markdown()`
 * is a `GithubFlavoredMarkdownConverter`, but league/commonmark's own defaults are
 * hostile to untrusted content: `html_input => allow`, `allow_unsafe_links => true`
 * and `max_nesting_level => PHP_INT_MAX` (vendor/league/commonmark/src/Environment/Environment.php).
 * A bare call would render an embedded `<script>` verbatim and let a `javascript:`
 * href through.
 *
 * `html_input` is set to `escape`, not `strip`: the operator must see the HTML
 * they typed rendered as literal text, rather than it silently vanishing.
 * `max_nesting_level` is bounded so a pathologically nested body cannot exhaust
 * the stack. Do not "simplify" this back to a bare `Str::markdown()`.
 */
final class MarkdownRenderer
{
    public function render(string $body): HtmlString
    {
        return new HtmlString(Str::markdown($body, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
