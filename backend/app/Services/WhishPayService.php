<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Whish Pay (Lebanon) for a merchant's own Whish business account. Each merchant gets a
 * "channel" and "secret" from Whish; NeoTalab creates a payment link per order and confirms it
 * by asking Whish for the collect status, callbacks and redirects are never trusted alone.
 * Credentials live in merchants.settings.whish (secret encrypted).
 */
class WhishPayService
{
    public const LIVE_URL = 'https://whish.money/itel-service/api';

    public const SANDBOX_URL = 'https://lb.sandbox.whish.money/itel-service/api';

    public function enabled(Merchant $merchant): bool
    {
        return $this->credentials($merchant) !== null;
    }

    /**
     * @return array{channel: string, secret: string, sandbox: bool}|null
     */
    public function credentials(Merchant $merchant): ?array
    {
        $whish = ($merchant->settings ?? [])['whish'] ?? [];
        $channel = trim((string) ($whish['channel'] ?? ''));
        $secret = (string) ($whish['secret'] ?? '');
        if ($channel === '' || $secret === '') {
            return null;
        }

        try {
            $secret = Crypt::decryptString($secret);
        } catch (\Throwable) {
            return null;
        }

        // Sandbox "payments" are not real money: outside testing they are never accepted as paid.
        $sandbox = (bool) ($whish['sandbox'] ?? false) && (bool) config('services.whish.sandbox_allowed');

        return ['channel' => $channel, 'secret' => $secret, 'sandbox' => $sandbox];
    }

    /**
     * Store credentials from the dashboard. An empty secret keeps the saved one.
     *
     * @param  array<string, mixed>  $settings  merchant settings, modified in place
     */
    public static function applySettings(array &$settings, ?string $channel, ?string $secret, ?bool $sandbox): void
    {
        $whish = $settings['whish'] ?? [];
        if ($channel !== null) {
            $whish['channel'] = trim($channel);
        }
        if ($secret !== null && trim($secret) !== '') {
            $whish['secret'] = Crypt::encryptString(trim($secret));
        }
        if ($channel !== null && trim($channel) === '') {
            unset($whish['secret']); // clearing the channel disconnects Whish
        }
        if ($sandbox !== null) {
            $whish['sandbox'] = $sandbox;
        }
        $settings['whish'] = $whish;
    }

    /**
     * Asks Whish whether this channel + secret are valid (the account balance call). false only when
     * Whish clearly refuses them; null when it could not tell (network, outage, unexpected answer),
     * so a Whish problem never blocks a shop from saving its settings.
     *
     * @param  array{channel: string, secret: string, sandbox: bool}  $creds
     */
    public function verify(array $creds): ?bool
    {
        try {
            $response = $this->client($creds)->get('/payment/account/balance');
        } catch (\Throwable) {
            return null;
        }

        if (in_array($response->status(), [401, 403], true) || ($response->successful() && $response->json('status') === false)) {
            return false;
        }

        return $response->successful() && $response->json('status') === true ? true : null;
    }

    /**
     * Creates the Whish payment link for a pending order and stores it on the order.
     */
    public function createLink(Merchant $merchant, Order $order): string
    {
        $creds = $this->credentials($merchant) ?? throw new RuntimeException('Whish is not connected for this shop.');
        [$amount, $currency] = $this->amount($merchant, $order);

        $externalId = $order->whish_external_id ?: (int) (now()->getTimestampMs() * 1000 + random_int(0, 999));
        // Relative signature: valid whatever scheme/host the proxy in front of the API reports.
        $callback = fn (string $result) => rtrim((string) config('app.url'), '/')
            .URL::signedRoute('whish.callback', ['order' => $order->id, 'result' => $result], null, false);
        $order->loadMissing('customer');
        $lang = in_array($order->customer?->locale, ['en', 'ar', 'fr'], true) ? $order->customer->locale : ($merchant->locale ?: 'en');
        $redirect = fn (string $result) => rtrim((string) config('app.frontend_url'), '/')."/pay/{$result}?lang={$lang}";

        $response = $this->client($creds)->post('/payment/whish', [
            'amount' => $amount,
            'currency' => $currency,
            'invoice' => "{$merchant->name} #{$order->number}",
            'externalId' => $externalId,
            'successCallbackUrl' => $callback('success'),
            'failureCallbackUrl' => $callback('failure'),
            'successRedirectUrl' => $redirect('success'),
            'failureRedirectUrl' => $redirect('failure'),
        ]);

        $url = $response->json('data.collectUrl');
        if (! $response->successful() || ! $response->json('status') || ! is_string($url)) {
            throw new RuntimeException('Whish did not return a payment link: '.($response->json('code') ?? $response->status()));
        }

        $order->forceFill(['whish_external_id' => $externalId, 'whish_collect_url' => $url])->save();

        return $url;
    }

    /**
     * The amount and currency are part of the query, so a payment for a different amount never
     * counts. 'unknown' = Whish could not be asked (network/server error, shop disconnected).
     *
     * @return 'success'|'failed'|'pending'|'unknown'
     */
    public function status(Merchant $merchant, Order $order): string
    {
        $creds = $this->credentials($merchant);
        if (! $creds || ! $order->whish_external_id) {
            return 'unknown';
        }

        [$amount, $currency] = $this->amount($merchant, $order);
        try {
            $response = $this->client($creds)->post('/payment/collect/status', [
                'amount' => $amount,
                'currency' => $currency,
                'externalId' => (int) $order->whish_external_id,
            ]);
        } catch (\Throwable) {
            return 'unknown';
        }

        if (! $response->successful() || ! $response->json('status')) {
            return 'unknown';
        }

        $status = $response->json('data.collectStatus');

        return in_array($status, ['success', 'failed'], true) ? $status : 'pending';
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function amount(Merchant $merchant, Order $order): array
    {
        return $order->payment_currency === 'LBP' && $order->total_lbp
            ? [(float) $order->total_lbp, 'LBP']
            : [(float) $order->total, strtoupper((string) $merchant->currency ?: 'USD')];
    }

    /**
     * Whish's live or sandbox address; a test lab may point to a stand-in, never in production.
     *
     * @param  array{channel: string, secret: string, sandbox: bool}  $creds
     */
    public function baseUrl(array $creds): string
    {
        $lab = (string) config('services.whish.base_url');
        if ($lab !== '' && ! app()->isProduction()) {
            return rtrim($lab, '/');
        }

        return $creds['sandbox'] ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /**
     * @param  array{channel: string, secret: string, sandbox: bool}  $creds
     */
    private function client(array $creds)
    {
        return Http::baseUrl($this->baseUrl($creds))
            ->acceptJson()
            ->timeout(20)
            ->withHeaders([
                'channel' => $creds['channel'],
                'secret' => $creds['secret'],
                'websiteurl' => parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: 'neotalab.com',
            ]);
    }
}
