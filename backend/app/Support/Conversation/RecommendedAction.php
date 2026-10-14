<?php

namespace App\Support\Conversation;

enum RecommendedAction: string
{
    case ShowMenu = 'show_menu';
    case AskClarification = 'ask_clarification';
    case ConfirmCart = 'confirm_cart';
    case Checkout = 'checkout';
    case PlaceOrder = 'place_order';
    case CancelOrder = 'cancel_order';
    case Handoff = 'handoff';
    case CheckAvailability = 'check_availability';
    case BookAppointment = 'book_appointment';
    case CancelAppointment = 'cancel_appointment';
    case CloseConversation = 'close_conversation';
    case AwaitCustomer = 'await_customer';
    case None = 'none';
}
