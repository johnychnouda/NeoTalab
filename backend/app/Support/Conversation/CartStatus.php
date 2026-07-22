<?php

namespace App\Support\Conversation;

enum CartStatus: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
}
