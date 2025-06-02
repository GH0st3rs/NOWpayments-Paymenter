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

    /**
     * Get all the configuration for the extension
     * 
     * @param array $values
     * @return array
     */
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
                'name'        => 'ipn_secret',
                'label'       => 'IPN Secret Key',
                'type'        => 'text',
                'description' => 'IPN (Instant payment notifications, or callbacks) are used to notify you when transaction status is changed.',
                'required'    => true,
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

    /**
     * Return a view or a url to redirect to
     * 
     * @param Invoice $invoice
     * @param float $total
     * @return string
     */
    public function pay(Invoice $invoice, $total)
    {
        $cacheKey = "nowpayments_payment_url_{$invoice->id}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $url = 'https://api.nowpayments.io/v1/invoice';
        $apiKey = trim($this->config('api_key'));
        $currency = strtolower($invoice->currency_code);
        $isFeePaidByUser = $this->config('is_fee_paid_by_user') === "1";

        $data = [
            'price_amount' => $total,
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
        Log::error('NOWPayments - Payment Error', ['response' => $response->body()]);
    }

    public function webhook(Request $request)
    {
        $sigString = $request->header('x-nowpayments-sig');
        if (!$sigString) {
            Log::error('NOWPayments - HMAC signature missing', ['request' => (string)$request->headers]);
            return response()->json(['success' => false, 'message' => 'Missing sign'], 400);
        }
        $body = $request->json()->all();
        Log::debug('NOWPayments - Wehbook json', ['request' => $body]);
        // Check signature
        $this->tksort($body);
        $sorted_request_json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $hmac = hash_hmac("sha512", $sorted_request_json, trim($this->config('ipn_secret')));
        if ($hmac !== $sigString) {
            Log::error('NOWPayments - HMAC signature does not match', ['Calculated' => $hmac, 'Received' => $sigString]);
            return response()->json(['success' => false, 'message' => 'HMAC signature does not match'], 400);
        }
        // Check payment status        
        if ($body['payment_status'] == 'finished') {
            $amount = $body["price_amount"] ?? 0;
            $fee = $body['fee']['serviceFee'] + $body['fee']['depositFee'];
            $transactionHash = $body['payin_hash'] ?? $body['payment_id'] ?? null;
            // Add payment
            ExtensionHelper::addPayment($body['order_id'], 'NOWPayments',  $amount, $fee, $transactionHash);
            return response()->json(['status' => 'success']);
        }
    }

    private function tksort(&$array)
    {
        ksort($array);
        foreach (array_keys($array) as $k) {
            if (gettype($array[$k]) == "array") {
                $this->tksort($array[$k]);
            }
        }
    }
}
