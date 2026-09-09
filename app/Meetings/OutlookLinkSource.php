<?php

namespace App\Meetings;

enum OutlookLinkSource
{
    case Feed;
    case Template;
    case Unavailable;
}
