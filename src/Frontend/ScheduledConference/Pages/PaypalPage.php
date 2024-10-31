<?php

namespace PaypalPayment\Frontend\ScheduledConference\Pages;

use App\Facades\Plugin;
use App\Frontend\ScheduledConference\Pages\ParticipantRegisterStatus;
use App\Models\Enums\RegistrationPaymentState;
use App\Models\Registration;
use App\Frontend\Website\Pages\Page;
use Illuminate\Support\Str;
use Omnipay\Omnipay;

class PaypalPage extends Page
{
    protected static string $view = 'PaypalPayment::frontend.scheduledConference.pages.paypal';

    function __invoke()
    {
        $request = app('request');
        $registrationId = $request->input('id');
        
        abort_if(!$registrationId, 404);

        $registration = Registration::withTrashed()
            ->where('id', $registrationId)
            ->first();

        abort_if(!$registration, 404);
        
        if($request->input('paymentId') && $request->input('PayerID') && $request->input('token')){
            return $this->completePayment($registration);
        }


        return $this->handlePayment($registration);
    }

    public function handlePayment(Registration $registration)
    {
        $paypalPlugin = Plugin::getPlugin('PaypalPayment'); 

        $gateway = Omnipay::create('PayPal_Rest');
		$gateway->initialize([
			'clientId' => $paypalPlugin->getClientId(),
			'secret' => $paypalPlugin->getClientSecret(),
			'testMode' => $paypalPlugin->isTestMode(),
		]);

        $transaction = $gateway->purchase(array(
            'amount' => number_format($registration->registrationPayment->cost),
            'currency' => $registration->registrationPayment->currency,
            'description' => $registration->registrationPayment->name,
            'returnUrl' => route(static::getRouteName(), ['id' => $registration->id]),
            'cancelUrl' => route(ParticipantRegisterStatus::getRouteName()),
        ));
        

        $response = $transaction->send();

        if ($response->isRedirect()) return redirect($response->getRedirectUrl());
        if (!$response->isSuccessful()) return abort(500, $response->getMessage());

        abort(500, 'PayPal response was not redirect!');
    }

    public function completePayment(Registration $registration)
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
			if (!$response->isSuccessful()) throw new \Exception($response->getMessage());

			$data = $response->getData();

			if ($data['state'] != 'approved') throw new \Exception('State ' . $data['state'] . ' is not approved!');
			if (count($data['transactions']) != 1) throw new \Exception('Unexpected transaction count!');
			$transaction = $data['transactions'][0];
            
			if ((float) $transaction['amount']['total'] != (float) $registration->registrationPayment->cost 
                || $transaction['amount']['currency'] != Str::upper($registration->registrationPayment->currency)){
                    throw new \Exception('Amounts (' . $transaction['amount']['total'] . ' ' . $transaction['amount']['currency'] . ' vs ' . $registration->registrationPayment->cost . ' ' . $registration->registrationPayment->currency . ') don\'t match!');
            }

            $registration->registrationPayment->update([
                'type' => 'Paypal',
                'state' => RegistrationPaymentState::Paid,
                'paid_at' => now(),
            ]);

            return redirect(route(ParticipantRegisterStatus::getRouteName()));
		} catch (\Exception $e) {
            abort(500, $e->getMessage());
		}
    }
}
