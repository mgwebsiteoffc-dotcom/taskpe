<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Whatify External API ("BYO-BSP") client.
 * Docs: https://whatify.in/api-docs
 *
 * Auth: X-API-Key header with the merchant's own key (they generate it in
 * their Whatify dashboard and paste it into our Settings page).
 */
class WhatifyClient
{
    public function __construct(
        protected string $apiKey,
        protected ?int $accountId = null,
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('whatify.timeout', 15))
            ->acceptJson()
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->baseUrl(rtrim((string) config('whatify.base_url'), '/'));
    }

    /** GET /ping — verify the key works. */
    public function ping(): array
    {
        return $this->http()->get('/ping')->throw()->json();
    }

    /** GET /whatsapp-accounts — sender numbers on the merchant's account. */
    public function accounts(): array
    {
        return $this->http()->get('/whatsapp-accounts')->throw()->json();
    }

    /** GET /templates?status=approved — approved templates for mapping. */
    public function templates(?string $status = 'approved'): array
    {
        $query = array_filter([
            'status'              => $status,
            'whatsapp_account_id' => $this->accountId,
        ]);

        return $this->http()->get('/templates', $query)->throw()->json('templates') ?? [];
    }

    /** GET /wallet/balance — shown in Settings so merchants see spend. */
    public function wallet(): array
    {
        return $this->http()->get('/wallet/balance')->throw()->json();
    }

    /**
     * POST /send-template — preferred path for unsolicited staff alerts
     * (works outside Meta's 24h window once the template is approved).
     */
    public function sendTemplate(string $phone, string $templateName, array $bodyParams = []): array
    {
        $payload = array_filter([
            'phone'               => self::normalizePhone($phone),
            'template_name'       => $templateName,
            'body_params'         => array_map('strval', array_values($bodyParams)),
            'whatsapp_account_id' => $this->accountId,
        ], fn ($v) => $v !== null);

        return $this->http()->post('/send-template', $payload)->json() ?? ['success' => false];
    }

    /**
     * POST /send-message — plain session text. Only delivers inside the
     * 24h customer-service window (staff replied to the number recently).
     */
    public function sendText(string $phone, string $message): array
    {
        $payload = array_filter([
            'phone'               => self::normalizePhone($phone),
            'message'             => mb_substr($message, 0, 4096),
            'whatsapp_account_id' => $this->accountId,
        ], fn ($v) => $v !== null);

        return $this->http()->post('/send-message', $payload)->json() ?? ['success' => false];
    }

    /** GET /messages/{id} — poll delivery status if needed. */
    public function messageStatus(int $messageId): array
    {
        return $this->http()->get("/messages/{$messageId}")->throw()->json();
    }

    /**
     * Normalize Indian numbers: strip junk, drop leading 0, prefix the
     * default country code when a bare 10-digit mobile is given.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');

        if (strlen($digits) === 10 && !str_starts_with($digits, (string) config('whatify.default_country_code', '91'))) {
            $digits = config('whatify.default_country_code', '91').$digits;
        }

        return $digits;
    }
}
