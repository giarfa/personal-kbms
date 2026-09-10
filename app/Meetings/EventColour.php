<?php

namespace App\Meetings;

/**
 * The closed palette a colour rule may name (US-012).
 *
 * The value is only ever used to build a class-name suffix — `kb-ev--colour-yellow`,
 * `kb-row--colour-yellow`. The colour itself is resolved in `resources/css/app.css`
 * from `--kb-rule-*` tokens, so configuration can never put a raw CSS value into
 * markup. Adding a colour here means adding its two tokens to the stylesheet in
 * both themes, on the same lightness pairs as the rest.
 */
enum EventColour: string
{
    case Yellow = 'yellow';
    case Purple = 'purple';
    case Green = 'green';
    case Blue = 'blue';
    case Orange = 'orange';
    case Grey = 'grey';
}
