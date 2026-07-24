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
    protected static string $view = 'PaypalPayment::panel.scheduledConference.pages.paypal';

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

        $transaction = $gateway->purchase([
            'amount' => number_format($paymentQueue->amount, 2, '.', ''),
            'currency' => $paymentQueue->currency,
            'description' => $paymentQueue->getMeta('title'),
            'returnUrl' => route(static::getRouteName(), ['id' => $paymentQueue->id]),
            'cancelUrl' => route(static::getRouteName(), ['id' => $paymentQueue->id]),
        ]);

        $response = $transaction->send();

        if ($response->isRedirect()) {
            return redirect($response->getRedirectUrl());
        }
        if (! $response->isSuccessful()) {
            Log::error('PayPal purchase initialization failed: '.$response->getMessage());

            return abort(403, 'Payment initialization failed. Please try again or contact support.');
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

            if (
                (float) $transaction['amount']['total'] != (float) $paymentQueue->amount
                || $transaction['amount']['currency'] != Str::upper($paymentQueue->currency)
            ) {
                $message = 'Amounts ('.$transaction['amount']['total'].' '.$transaction['amount']['currency'].' vs '.$paymentQueue->amount.' '.$paymentQueue->currency.') don\'t match!';
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
}
