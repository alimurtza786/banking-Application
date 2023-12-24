<?php

/**
 * @package StripeProcessor
 * @author tehcvillage <support@techvill.org>
 * @contributor Md Abdur Rahaman Zihad <[zihad.techvill@gmail.com]>
 * @created 21-12-2022
 */


namespace App\Services\Gateways\Stripe;

use App\Models\{
    CurrencyPaymentMethod,
    Currency
};
use App\Services\Gateways\Gateway\Exceptions\{
    GatewayInitializeFailedException,
    PaymentFailedException
};
use App\Services\Gateways\Gateway\PaymentProcessor;
use Stripe\StripeClient;
use Throwable;


/**
 * @method array pay()
 */
class StripeProcessor extends PaymentProcessor
{
    protected $data;

    protected $currency;

    protected $stripe;

    /**
     * Initiate the stripe payment process
     *
     * @param array $data
     *
     * @return void
     */
    protected function initiatePayment(array $data)
    {
        $this->validateInitializationRequest($data);

        // Boot stripe payment initiator
        $this->boot($data);

        // create payment intent
        return $this->createPaymentIntent(
            $this->stripe->secret_key,
            $data['total_amount'],
            $this->currency,
            $data['card'],
            $data['month'],
            $data['year'],
            $data['cvc']
        );
    }

    /**
     * Boot stripe payment processor
     *
     * @param array $data
     *
     * @return void
     */
    protected function boot($data)
    {
        $this->data = $data;

        $currency = Currency::whereId($data['currency_id'])->first();

        if (is_null($currency)) {
            throw new GatewayInitializeFailedException(__("Currency method not found."));
        }

        $this->currency = $currency->code;

        $paymentMethod = CurrencyPaymentMethod::query()
            ->where(['currency_id' => $currency->id, 'method_id' => $data['payment_method_id']])->where('activated_for', 'like', "%" . $this->getPaymentType() . "%")->first(['method_data']);

        if (is_null($paymentMethod)) {
            throw new GatewayInitializeFailedException(__("Payment method not found."));
        }
        $this->stripe = json_decode($paymentMethod->method_data);
        if (!$this->stripe->secret_key) {
            throw new GatewayInitializeFailedException(__("Stripe initialize failed."));
        }
    }


    /**
     * Create payment intent for stripe
     *
     * @param string $secretKey
     * @param string $amount
     * @param string $currency
     * @param string $cardNumber
     * @param string $month
     * @param string $year
     * @param string $cvc
     *
     * @return array
     *
     * @throws PaymentFailedException
     */
    protected function createPaymentIntent($secretKey, $amount, $currency, $cardNumber, $month, $year, $cvc)
    {
        try {
            $stripe = new StripeClient($secretKey);

            $method = $stripe->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number'    => $cardNumber,
                    'exp_month' => $month,
                    'exp_year'  => $year,
                    'cvc'       => $cvc,
                ],
            ]);

            $intent = $stripe->paymentIntents->create([
                "amount" => round($amount * $this->resolveFactor($currency)),
                "currency" => $currency
            ]);

            return array_merge($this->data, [
                "payment_intent" => $intent->id,
                "payment_method" => $method->id,
                "gateway" => $this->gateway(),
                "total_amount" => $amount,
            ]);
        } catch (\Throwable $th) {
            throw new PaymentFailedException(__("Stripe payment inititalization failed."), $th->getMessage());
        }
    }


    /**
     * Confirm payment for stripe
     *
     * @param array $data
     *
     * @return mixed
     *
     * @throws PaymentFailedException
     */
    public function pay(array $data): array
    {
        $this->boot($data);

        $this->validatePaymentConfirmRequest($data);
        try {
            $stripe = new StripeClient($this->stripe->secret_key);

            $response = $stripe->paymentIntents->confirm($data['payment_intent'], [
                'payment_method' => $data['payment_method']
            ]);

            if (is_null($response)) {
                throw new PaymentFailedException(__("Stripe payment failed."));
            }

            if ($response->status == 'requires_action') {
                throw new PaymentFailedException(__("3DS cards are not supported."), ["response" => $response]);
            }

            if ($response->status != 'succeeded') {
                throw new PaymentFailedException($response->message, ["response" => $response]);
            }

            return [
                "action" => "success",
                "message" => __("Payment successful."),
                "payment_id" => $response->id,
                "response" => $response
            ];
        } catch (Throwable $th) {
            throw new PaymentFailedException($th->getMessage(), ["response" => $response ?? null]);
        }
    }


    /**
     * Get gateway alias name
     *
     * @return string
     */
    public function gateway(): string
    {
        return "stripe";
    }


    /**
     * Validate payment confirm request
     *
     * @param array $data
     *
     * @return array
     */
    private function validatePaymentConfirmRequest($data)
    {
        $rules = [
            'payment_intent'  => 'required',
            'payment_method' => 'required',
        ];
        return $this->validateData($data, $rules);
    }


    /**
     * Validate initialization request
     *
     * @param array $data
     *
     * @return array
     */
    private function validateInitializationRequest($data)
    {
        $rules = [
            'card'  => 'required',
            'month' => 'required|digits_between:1,12|numeric',
            'year' => 'required|numeric',
            'cvc' => 'required|numeric',
            'amount' => 'required|numeric',
        ];
        return $this->validateData($data, $rules);
    }

    public function resolveFactor($currency)
    {
        $zeroDecimalCurrencies = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return 1;
        }

        return 100;
    }
}
