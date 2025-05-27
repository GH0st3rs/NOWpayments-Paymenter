<?php

namespace Paymenter\Extensions\Gateways\NOWPayments;

use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NOWPayments extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Register webhook route
    }
    public function getMetadata()
    {
        return [
            'display_name' => 'NOWPayments',
            'version'      => '1.0.0',
            'author'       => 'GH0st3rs',
            'website'      => 'https://github.com/GH0st3rs/NOWpayments-paymenter.git',
        ];
    }
    public function getConfig($values = [])
    {
        return [
            [
                'name'     => 'api_key',
                'label'    => 'API Key',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'        => 'is_fee_paid_by_user',
                'label'       => 'Fees paid by users',
                'type'        => 'checkbox',
                'description' => 'Required for fixed-rate exchanges with all fees paid by users',
                'required'    => false,
            ],
        ];
    }
    public function pay(Invoice $invoice, $total)
    {
        $cacheKey = "nowpayments_payment_url_{$invoice->id}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = 'https://api.nowpayments.io/v1/invoice';
        $apiKey = trim($this->config('api_key'));
        $currency = strtolower($invoice->currency_code);
        $isFeePaidByUser = $this->config('is_fee_paid_by_user') ?? false;

        $data = [
            'price_amount' => number_format($total, 2, '.', ''),
            'price_currency' => $currency,
            'order_id' => (string) $invoice->id,
            'order_description' => (string)$invoice->items->map(fn($item) => $item->reference->product->name . " x " . $item->reference->quantity),
            'ipn_callback_url' => url('/extensions/gateways/nowpayments/webhook'),
            'cancel_url' => route('invoices.show', $invoice),
            'success_url' => route('invoices.show', $invoice),
            'is_fee_paid_by_user' => $isFeePaidByUser,
        ];

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->post($url, $data);

        if ($response->successful()) {
            $paymentUrl = $response->json()['invoice_url'] ?? null;
            if ($paymentUrl) {
                Cache::put($cacheKey, $paymentUrl, 3600);
                return $paymentUrl;
            }
        }
        Log::error('NOWPayments Payment Error', ['response' => $response->body()]);
    }

    public function webhook(Request $request)
    {
    }
}
