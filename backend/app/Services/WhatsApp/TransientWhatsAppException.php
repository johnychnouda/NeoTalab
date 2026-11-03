<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** Meta could not take the message right now (no connection, rate limit, 5xx): trying again later can work. */
class TransientWhatsAppException extends RuntimeException {}
