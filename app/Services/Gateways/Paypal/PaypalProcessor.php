<?php

/**
 * @package PaypalProcessor
 * @author tehcvillage <support@techvill.org>
 * @contributor Md Abdur Rahaman Zihad <[zihad.techvill@gmail.com]>
 * @created 21-12-2022
 */


namespace App\Services\Gateways\Paypal;

use App\Services\Gateways\Gateway\Exceptions\PaymentFailedException;
use App\Services\Gateways\Gateway\PaymentProcessor;

class PaypalProcessor extends PaymentProcessor
{

    public function pay(array $data): array
    {
        if (
            !isset($data["details"])
            || !is_array($data["details"])
            || !isset($data["details"]["status"])
            || $data["details"]["status"] != "COMPLETED"
        ) {
            throw new PaymentFailedException(__("Payment is not successful."), $data);
        }

        return [
            "action" => "success",
            "message" => __("Payment successful.")
        ];
    }

    public function gateway(): string
    {
        return "paypal";
    }
}
