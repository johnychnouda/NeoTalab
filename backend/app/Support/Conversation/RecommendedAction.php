<?php

namespace App\Support\Conversation;

enum RecommendedAction: string
{
    case ShowMenu = 'show_menu';
    case AskClarification = 'ask_clarification';
    case ConfirmCart = 'confirm_cart';
    case CloseConversation = 'close_conversation';
    case AwaitCustomer = 'await_customer';
    case None = 'none';
}
