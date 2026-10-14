<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Railway blocks SMTP ports on its Free/Hobby plans, so production email (error alerts) goes
 * through Resend's web API: MAIL_MAILER=resend + RESEND_API_KEY.
 */
class MailTransportTest extends TestCase
{
    public function test_the_resend_mailer_is_available(): void
    {
        config(['services.resend.key' => 're_test_key']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }
}
