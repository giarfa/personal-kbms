<?php

namespace App\Meetings;

/**
 * Which way along a recurring series a neighbour lies (US-018).
 *
 * A type rather than a `'prev'`/`'next'` string so the lookup, the value
 * object and the view cannot disagree about the vocabulary, and so the two
 * directions stay exhaustive — there is no third way out of an occurrence.
 */
enum SeriesDirection: string
{
    case Previous = 'previous';

    case Next = 'next';
}
