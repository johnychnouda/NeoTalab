<?php

namespace App\Support\Conversation;

enum ConversationIntent: string
{
    case Greeting = 'greeting';
    case Order = 'order';
    case ModifyCart = 'modify_cart';
    case Inquiry = 'inquiry';
    case Cancel = 'cancel';
    case Unknown = 'unknown';
}
