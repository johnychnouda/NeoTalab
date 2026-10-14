<?php

namespace App\Support\Conversation;

enum ConversationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
