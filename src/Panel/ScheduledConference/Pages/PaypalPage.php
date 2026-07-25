<?php

namespace PaypalPayment\Panel\ScheduledConference\Pages;

use App\Facades\Plugin;
use App\Managers\PaymentManager;
use App\Models\Payment;
use App\Panel\ScheduledConference\Pages\PaymentDetail;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Omnipay\Omnipay;

class PaypalPage extends Page
{
    public function getView(): string
    {
        return 'PaypalPayment::panel.scheduledConference.pages.paypal';
    }

    protected static bool $shouldRegisterNavigation = false;

    public function __invoke()
    {
        $request = app('request');
        $id = $request->input('id');

        abort_if(! $id, 404);

        $paymentQueue = Payment::query()
            ->where('id', $id)
            ->first();

        abort_if(! $paymentQueue, 404);

        // SECURITY HARDEING: Verify that the current user owns this payment queue or has editor permission
        abort_if(
            $paymentQueue->user_id !== auth()->id() && ! (auth()->check() && auth()->user()->can('update', app()->getCurrentScheduledConference())),
            403,
            'Unauthorized access to payment queue'
        );

        abort_if($paymentQueue->isExpired(), 403, 'Payment Queue expired');

        if ($request->input('paymentId') && $request->input('PayerID') && $request->input('token')) {
            return $this->completePayment($paymentQueue);
        }

        return $this->handlePayment($paymentQueue);
    }

    public function handlePayment(Payment $paymentQueue)
    {
        $paypalPlugin = Plugin::getPlugin('PaypalPayment');

        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->initialize([
            'clientId' => $paypalPlugin->getClientId(),
            'secret' => $paypalPlugin->getClientSecret(),
            'testMode' => $paypalPlugin->isTestMode(),
        ]);

        $currency = strtoupper($paymentQueue->currency ?? 'USD');

        abort_if(
            ! $this->isSupportedPayPalCurrency($currency),
            400,
            "PayPal does not support transactions in {$currency}. Please use a supported currency (such as USD or EUR) or select an alternative payment method."
        );

        $returnRoute = static::getPanelRouteName('scheduledConference');

        $transaction = $gateway->purchase([
            'amount' => number_format($paymentQueue->amount, 2, '.', ''),
            'currency' => $currency,
            'description' => $paymentQueue->getMeta('title') ?? ('Payment #'.$paymentQueue->id),
            'returnUrl' => route($returnRoute, ['id' => $paymentQueue->id]),
            'cancelUrl' => route($returnRoute, ['id' => $paymentQueue->id]),
        ]);

        $response = $transaction->send();

        if ($response->isRedirect()) {
            return redirect($response->getRedirectUrl());
        }
        if (! $response->isSuccessful()) {
            Log::error('PayPal purchase initialization failed: '.$response->getMessage());

            return abort(403, 'Payment initialization failed ('.$response->getMessage().'). Please try again or contact support.');
        }

        abort(403, 'PayPal response was not redirect!');
    }

    public function completePayment(Payment $paymentQueue)
    {
        try {
            $request = app('request');
            $paypalPlugin = Plugin::getPlugin('PaypalPayment');

            $gateway = Omnipay::create('PayPal_Rest');
            $gateway->initialize([
                'clientId' => $paypalPlugin->getClientId(),
                'secret' => $paypalPlugin->getClientSecret(),
                'testMode' => $paypalPlugin->isTestMode(),
            ]);

            $transaction = $gateway->completePurchase([
                'payer_id' => $request->input('PayerID'),
                'transactionReference' => $request->input('paymentId'),
            ]);

            $response = $transaction->send();
            if (! $response->isSuccessful()) {
                Log::error('PayPal completePurchase failed: '.$response->getMessage());
                abort(403, 'Payment verification failed with PayPal.');
            }

            $data = $response->getData();

            if (($data['state'] ?? null) !== 'approved') {
                Log::warning('PayPal payment state not approved', ['state' => $data['state'] ?? null]);
                abort(403, 'Payment was not approved by PayPal.');
            }

            if (count($data['transactions'] ?? []) !== 1) {
                Log::error('PayPal unexpected transaction count in callback');
                abort(403, 'Unexpected transaction format received from PayPal.');
            }
            $transaction = $data['transactions'][0];

            $currency = strtoupper($paymentQueue->currency ?? 'USD');

            if (
                (float) $transaction['amount']['total'] != (float) $paymentQueue->amount
                || $transaction['amount']['currency'] != $currency
            ) {
                $message = 'Amounts ('.$transaction['amount']['total'].' '.$transaction['amount']['currency'].' vs '.$paymentQueue->amount.' '.$currency.') don\'t match!';
                Log::error('PayPal amount mismatch: '.$message);

                abort(403, 'Payment amount mismatch detected.');
            }

            $paymentManager = PaymentManager::get();
            $paymentManager->fulfillQueued($paymentQueue, 'paypal', auth()?->id());

            $paymentQueue->setMeta('paypal_payment_id', $request->input('paymentId'));
            $paymentQueue->setMeta('paypal_token', $request->input('token'));
            $paymentQueue->setMeta('paypal_payer_id', $request->input('PayerID'));

            Notification::make()
                ->title('Payment Success')
                ->success()
                ->send();

            return redirect()->to(PaymentDetail::getUrl(['record' => $paymentQueue]));
        } catch (\Exception $e) {
            Log::error('PayPal completion error: '.$e->getMessage());
            abort(403, 'An error occurred while completing payment verification.');
        }
    }

    protected function isSupportedPayPalCurrency(string $currency): bool
    {
        $supportedCurrencies = [
            'USD', 'EUR', 'GBP', 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK',
            'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK',
            'PHP', 'PLN', 'RUB', 'SGD', 'SEK', 'CHF', 'THB',
        ];

        return in_array(strtoupper($currency), $supportedCurrencies, true);
    }

    protected static function getPanelRouteName(string $panelName = 'scheduledConference'): string
    {
        $paramType = (new \ReflectionMethod(static::class, 'getRouteName'))->getParameters()[0]->getType()?->getName();
        $panelArg = ($paramType === 'string')
            ? $panelName
            : \Filament\Facades\Filament::getPanel($panelName);

        return static::getRouteName($panelArg);
    }
}

