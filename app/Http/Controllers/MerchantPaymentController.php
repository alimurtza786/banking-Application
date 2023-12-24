<?php

namespace App\Http\Controllers;

use Exception, DB, Validator, Session, Auth;
use App\Services\MerchantPaymentService;
use App\Repositories\StripeRepository;
use Illuminate\Http\Request;
use App\Http\Helpers\Common;
use App\Models\{CurrencyPaymentMethod,
    PaymentMethod,
    Merchant,
    Currency,
    Wallet
};
use App\Services\Mail\MerchantPayment\{NotifyMerchantOnPaymentMailService,
    NotifyAdminOnPaymentMailService
};

class MerchantPaymentController extends Controller
{
    protected $helper, $stripeRepository, $merchantService;

    public function __construct()
    {
        $this->helper = new Common();
        $this->stripeRepository = new StripeRepository();
        $this->merchantService = new MerchantPaymentService();
    }

    public function index(Request $request)
    {
        $merchantId = $request->merchant_id;
        $merchantUuid = $request->merchant;
        $merchantCurrencyId = $request->currency_id;

        $data['merchant'] = $merchant = Merchant::with(['currency:id,code','user:id,status'])->where(['id' => $merchantId, 'merchant_uuid' => $merchantUuid, 'currency_id' => $merchantCurrencyId])->first(['id', 'user_id', 'currency_id', 'status']);

        if (!$merchant || $merchant->status != 'Approved') {
            $this->helper->one_time_message('error', __('Merchant not found!'));
            return redirect('payment/fail');
        }

        //Check whether merchant is suspended
        $checkStandardMerchantUser = $this->helper->getUserStatus($merchant?->user?->status);
        
        if ($checkStandardMerchantUser == 'Suspended') {
            $data['message'] = __('Merchant is suspended!');
            return view('merchantPayment.user_suspended', $data);
        }

        //Check whether merchant is Inactive
        if ($checkStandardMerchantUser == 'Inactive') {
            $data['message'] = __('Merchant is inactive!');
            return view('merchantPayment.user_inactive', $data);
        }

        //for payUmoney
        if ($merchant->currency?->code == "INR") {
            Session::put('payumoney_currency_code', $merchant->currency?->code);
        }

        //For showing the message that merchant available or not
        $data['isMerchantAvailable'] = true;
        $data['paymentInfo'] = $request->all();

        //Payeer, Coinpayments removed
        $data['payment_methods'] = PaymentMethod::whereStatus('Active')->whereNotIn('name', ['Payeer', 'Coinpayments'])->get(['id', 'name'])->toArray();
        $cpmWithoutMts = CurrencyPaymentMethod::where(['currency_id' => $merchant?->currency?->id])->where('activated_for', 'like', "%deposit%")->pluck('method_id')->toArray();

        $paymoney = PaymentMethod::whereName('Mts')->first(['id']);
        array_push($cpmWithoutMts, $paymoney->id);
        $data['cpm'] = $cpmWithoutMts;

        return view('merchantPayment.index', $data);
    }

    public function showPaymentForm(Request $request) 
    {
        $merchantId = $request->merchant_id;
        $merchantUuid = $request->merchant;
        $merchantCurrencyId = $request->currency_id;

        $merchant = Merchant::with(['currency:id,code','user:id,status', 'merchant_group:id,fee_bearer'])->where(['id' => $merchantId, 'merchant_uuid' => $merchantUuid, 'currency_id' => $merchantCurrencyId])->first(['id', 'user_id', 'currency_id', 'status', 'fee', 'merchant_group_id']);

        if (!$merchant) {
            $this->helper->one_time_message('error', __('Merchant not found!'));
            return redirect('payment/fail');
        }

        $data = [
            'merchant' => $merchant,
            'paymentInfo' => $request->all(),
        ];

        $feesLimit = $this->merchantService->checkMerchantPaymentFeesLimit($merchantCurrencyId, constant($request->method), $request->amount, $merchant->fee);
        $data['totalFee'] = $totalFee = $feesLimit['totalFee'];
        $data['feeBearer'] = $merchant?->merchant_group?->fee_bearer;
        $data['totalAmount'] = $merchant?->merchant_group?->fee_bearer == 'Merchant' ? $request->amount : $request->amount + $totalFee;

        $paypal = PaymentMethod::whereName("Paypal")->first(['id', 'name']);
        $paypalCurrencyPaymentMethod = CurrencyPaymentMethod::where(['currency_id' => $merchant->currency?->id, 'method_id' => $paypal->id])->where('activated_for', 'like', "%deposit%")->first(['method_data']);

        if (!empty($paypalCurrencyPaymentMethod)) {
            $data['clientId']     = json_decode($paypalCurrencyPaymentMethod->method_data)->client_id;
            $data['currencyCode'] = $merchant?->currency?->code;
        }

        $view = $request->method == 'Mts' ? 'merchantPayment.wallet' : 'merchantPayment.'. strtolower($request->method);

        return view($view, $data);
    }

    /*System Merchant Payment Starts*/
    public function mtsPayment(Request $request)
    {
        $data = $request->only('email', 'password');
        $merchant = Merchant::with('merchant_group:id,fee_bearer')->find($request->merchant, ['id', 'user_id', 'status', 'fee', 'merchant_group_id']);

        if (!$merchant || $merchant->status != 'Approved') {
            $this->helper->one_time_message('error', __('Merchant not found!')); //TODO - translations
            return redirect('payment/fail');
        }

        $curr = Currency::whereCode($request->currency)->first(['id', 'code']);
        
        if (!$curr) {
            $this->helper->one_time_message('error', __('Currency does not exist in the system.'));
            return redirect('payment/fail');
        }

        if (!Auth::attempt($data)) {
            $this->helper->one_time_message('danger', __('Unable to login with provided credentials!'));
            return redirect('payment/fail');
        }

        //Merchant cannot make payment to himself
        if ($merchant->user_id == auth()->id()) {
            auth()->logout();
            $this->helper->one_time_message('error', __('Merchant cannot make payment to himself!'));
            return redirect('payment/fail');
        }

        //Check whether user is suspended
        $checkPaidByUser = $this->helper->getUserStatus(auth()->user()->status);
        
        if ($checkPaidByUser == 'Suspended') {
            $data['message'] = __('You are suspended to do any kind of transaction!');
            return view('merchantPayment.user_suspended', $data);
        }

        //Check whether user is inactive
        if ($checkPaidByUser == 'Inactive') {
            $data['message'] = __('Your account is inactivated. Please try again later!');
            return view('merchantPayment.user_inactive', $data);
        }

        //Check whether merchant is suspended
        $checkStandardMerchantUser = $this->helper->getUserStatus($merchant->user?->status);
        
        if ($checkStandardMerchantUser == 'Suspended') {
            $data['message'] = __('Merchant is suspended!');
            return view('merchantPayment.user_suspended', $data);
        }

        //Check whether merchant is Inactive
        if ($checkStandardMerchantUser == 'Inactive') {
            DB::rollBack();
            $data['message'] = __('Merchant is inactive!');
            return view('merchantPayment.user_inactive', $data);
        }

        $senderWallet = Wallet::where(['user_id' => auth()->user()->id, 'currency_id' => $curr->id])->first(['id', 'balance']);
        //Check User has the wallet or not
        if (!$senderWallet) {
            auth()->logout();
            $this->helper->one_time_message('error', __('User does not have the wallet - :x. Please exchange to wallet - :y', ['x' => $curr->code, 'y' => $curr->code]));
            return redirect('payment/fail');
        }
        //Merchant payment fee calculation
        $feesLimit = $this->merchantService->checkMerchantPaymentFeesLimit($curr->id, 1, $request->amount, $merchant->fee);

        $totalAmount = $merchant?->merchant_group?->fee_bearer == 'Merchant' ? $request->amount : $request->amount + $feesLimit['totalFee'];

        //Check user balance
        if ($senderWallet->balance < $totalAmount) {
            auth()->logout();
            $this->helper->one_time_message('error', __("User does not have sufficient balance!"));
            return redirect('payment/fail');
        }

        $uniqueCode = unique_code();

        try {
            DB::beginTransaction();

            //Merchant payment
            $merchantPayment = $this->merchantService->makeMerchantPayment($request, $merchant, $feesLimit, $curr->id, $uniqueCode);

            $senderWallet->balance = $merchant?->merchant_group?->fee_bearer == 'Merchant' ? ($senderWallet->balance - $request->amount) : $senderWallet->balance - ($request->amount + $feesLimit['totalFee']) ;
            $senderWallet->save();

             //User Transaction
            $this->merchantService->makeUserTransaction($request, $merchant, $feesLimit, $curr->id, $uniqueCode, $merchantPayment);

            //Merchant Transaction
            $this->merchantService->makeMerchantTransaction($request, $merchant, $feesLimit, $curr->id, $uniqueCode, $merchantPayment);

            $merchantWallet = Wallet::where(['user_id' => $merchant->user_id, 'currency_id' => $curr->id])->first(['id', 'balance']);

            //merchant wallet create or update
            $this->merchantService->createOrUpdateMerchantWallet($request->amount, $merchant, $curr->id, $feesLimit['totalFee'], $merchantWallet);

            DB::commit();

            // Send mail to admin
            (new NotifyAdminOnPaymentMailService())->send($merchantPayment, ['type' => 'payment', 'medium' => 'email', 'fee_bearer' => $merchant?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            // Send mail to merchant
            (new NotifyMerchantOnPaymentMailService())->send($merchantPayment, ['fee_bearer' => $merchant?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            return redirect('payment/success');
        } catch (Exception $e) {
            DB::rollBack();
            $this->helper->one_time_message('error', $e->getMessage());
            return redirect('payment/fail');
        }
    }
    /*System Merchant Payment ends*/

    /*Stripe Merchant Payment Starts*/
    public function stripeMakePayment(Request $request)
    {
        $data = ['status' => 200, 'message' => "Success"];

        $validation = Validator::make($request->all(), [
            'cardNumber' => 'required',
            'month'      => 'required|digits_between:1,12|numeric',
            'year'       => 'required|numeric',
            'cvc'        => 'required|numeric',
            'currency'   => 'required',
            'merchant'   => 'required',
            'amount'     => 'required',
        ]);

        if ($validation->fails()) {
            $data['message'] = $validation->errors()->first();
            $data['status']  = 401;
            return response()->json([
                'data' => $data
            ]);
        }

        $amount = $request->amount;
        $paymentMethod = PaymentMethod::whereName("Stripe")->first(['id', 'name']);
        $methodId = $paymentMethod['id'];
        $currencyCode = $request->currency;
        $currency = Currency::where(['code'=> $currencyCode])->first(['id', 'code']);

        $currencyPaymentMethod = CurrencyPaymentMethod::where(['currency_id' => $currency['id'], 'method_id' => $methodId])->where('activated_for', 'like', "%deposit%")->first(['method_data']);
        $methodData = json_decode($currencyPaymentMethod->method_data);
        $secretKey = $methodData->secret_key;

        if (!isset($secretKey)) {
            $data['message']  = __("Payment gateway credentials not found!");
            return response()->json([
                'data' => $data
            ]);
        }

        $response = $this->stripeRepository->makePayment($secretKey, round($amount, 2), strtolower($currency->code), $request->cardNumber, $request->month, $request->year, $request->cvc);

        if ($response->getData()->status != 200) {
            $data['status']  = $response->getData()->status;
            $data['message'] = $response->getData()->message;
        } else {
            $data['paymentIntendId'] = $response->getData()->paymentIntendId;
            $data['paymentMethodId'] = $response->getData()->paymentMethodId;
        }

        return response()->json([
            'data' => $data
        ]);
    }

    public function stripePayment(Request $request)
    {
        $data = ['message' => "Fail", 'status' => 401];

        try {
            $validation = Validator::make($request->all(), [
                'amount'      => 'required|numeric',
                'merchant'    => 'required',
                'paymentIntendId'  => 'required',
                'paymentMethodId'  => 'required',
            ]);

            if ($validation->fails()) {
                $data['message'] = $validation->errors()->first();
                return response()->json(['data' => $data]);
            }

            $merchantCheck = Merchant::with('merchant_group:id,fee_bearer')->find($request->merchant, ['id', 'user_id', 'status', 'fee', 'merchant_group_id']);

            if (!$merchantCheck || $merchantCheck->status != 'Approved') {
                $data['message'] = __('Merchant not found!');
                return response()->json(['data' => $data]);
            }

            DB::beginTransaction();
            
            $amount = (double) $request->amount;
            $currencyCode = $request->currency;
            $uniqueCode = unique_code();

            $currency = Currency::where('code', $currencyCode)->first(['id', 'code']);

            $paymentMethod = PaymentMethod::whereName('Stripe')->first(['id']);

            $currencyPaymentMethod = CurrencyPaymentMethod::where(['currency_id' => $currency->id, 'method_id' => $paymentMethod->id])->where('activated_for', 'like', "%deposit%")->first(['method_data']);
            $methodData = json_decode($currencyPaymentMethod->method_data);

            if (empty($methodData) || !isset($methodData->secret_key)) {
                $data['message'] = __('method data of currency :x not found!', ['x' => $currencyCode]);
                return response()->json(['data' => $data]);
            }

            $response = $this->stripeRepository->paymentConfirm($methodData->secret_key, $request->paymentIntendId, $request->paymentMethodId);

            if ($response->getData()->status != 200) {
                $data['message'] = $response->getData()->message;
                return response()->json(['data' => $data]);
            }

            $token = $response->getData()->id;

            //Deposit + Merchant Fee 
            $feesLimit = $this->merchantService->checkMerchantPaymentFeesLimit($currency->id, $paymentMethod->id, $amount, $merchantCheck->fee);

            //Merchant payment
            $merchantPayment = $this->merchantService->makeMerchantPayment($request, $merchantCheck, $feesLimit, $currency->id, $uniqueCode, $token, Stripe);

            //Merchant Transaction
            $this->merchantService->makeMerchantTransaction($request, $merchantCheck, $feesLimit, $currency->id, $uniqueCode, $merchantPayment, $paymentMethod->id);

            //Add Amount to Merchant Wallet
            $merchantWallet = Wallet::where(['user_id' => $merchantCheck->user_id, 'currency_id' => $currency->id])->first(['id', 'balance']);

            //merchant wallet create or update
            $this->merchantService->createOrUpdateMerchantWallet($request->amount, $merchantCheck, $currency->id, $feesLimit['totalFee'], $merchantWallet);

            DB::commit();

            // Send mail to admin
            (new NotifyAdminOnPaymentMailService())->send($merchantPayment, ['type' => 'payment', 'medium' => 'email', 'fee_bearer' => $merchantCheck?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            // Send mail to merchant
            (new NotifyMerchantOnPaymentMailService())->send($merchantPayment, ['fee_bearer' => $merchantCheck?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            Session::put('merchant_amount', $amount);
            Session::put('merchant_currency_code', $currencyCode);
            $data['message'] = "Success";
            $data['status']  = 200;
        } catch (Exception $e) {
            DB::rollBack();
            $data['message'] =  $e->getMessage();
        }
        return response()->json(['data' => $data]);
    }
    /*Stripe Merchant Payment Starts*/

    /*PayPal Merchant Payment Starts*/

    public function paypalPaymentSuccess(Request $request)
    {
        $data = ['status' => 401, 'redirectedUrl' => "/payment/fail"];

        try {
            $uniqueCode        = unique_code();
            $amount            = $request->amount;
            $paymentMethod     = PaymentMethod::whereName("Paypal")->first(['id', 'name']);
            $payment_method_id = $paymentMethod['id'];
            $merchant          = $request->merchant;
            $currencyCode      = $request->currency;
            $currency          = Currency::whereCode($currencyCode)->first(['id', 'code']);
            $currencyId        = $currency['id'];

            $merchantInfo = Merchant::with('merchant_group:id,fee_bearer')->find($merchant, ['id', 'user_id', 'fee', 'merchant_group_id']);

            //Deposit + Merchant Fee 
            $feesLimit = $this->merchantService->checkMerchantPaymentFeesLimit($currencyId, $payment_method_id, $amount, $merchantInfo->fee);
            DB::beginTransaction();

            //Merchant payment
            $merchantPayment = $this->merchantService->makeMerchantPayment($request, $merchantInfo, $feesLimit, $currency->id, $uniqueCode, Paypal, Paypal);

            //Merchant Transaction
            $this->merchantService->makeMerchantTransaction($request, $merchantInfo, $feesLimit, $currency->id, $uniqueCode, $merchantPayment, $paymentMethod->id);

            $merchantWallet = Wallet::where(['user_id' => $merchantInfo->user_id, 'currency_id' => $currencyId])->first(['id', 'balance']);

            //merchant wallet create or update
            $this->merchantService->createOrUpdateMerchantWallet($request->amount, $merchantInfo, $currency->id, $feesLimit['totalFee'], $merchantWallet);

            DB::commit();
            
            // Send mail to admin
            (new NotifyAdminOnPaymentMailService())->send($merchantPayment, ['type' => 'payment', 'medium' => 'email', 'fee_bearer' => $merchantInfo?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            // Send mail to merchant
            (new NotifyMerchantOnPaymentMailService())->send($merchantPayment, ['fee_bearer' => $merchantInfo?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

            $data["redirectedUrl"] = "/payment/success";
            $data['status']        = 200;
        } catch (Exception $e) {
            DB::rollBack();
            $data['message'] = $e->getMessage();
        }
        return response()->json(['data' => $data]);
    }
    /*PayPal Merchant Payment ends*/

    /*PayUMoney Merchant Payment Starts*/
    public function payumoney(Request $request)
    {
        if (session('payumoney_currency_code') != 'INR') {
            $this->helper->one_time_message('error', __('PayUMoney only supports Indian Rupee(INR)'));
            return redirect('payment/fail');
        }

        $paymentMethod = PaymentMethod::whereName('PayUmoney')->first(['id']);

        $currency = Currency::whereCode(session('payumoney_currency_code'))->first(['id']);

        $currencyPaymentMethod = CurrencyPaymentMethod::where(['currency_id' => $currency->id, 'method_id' => $paymentMethod->id])->where('activated_for', 'like', "%deposit%")->first();
        
        if (empty($currencyPaymentMethod)) {
            return redirect('payment/fail');
        }

        $methodData        = json_decode($currencyPaymentMethod->method_data);
        $data['amount']    = number_format((float) $request->amount, 2, '.', ''); //Payumoney accepts 2 decimal places only - if not rounded to 2 decimal places, Payumoney will throw.
        $data['mode']      = $methodData->mode;
        $data['key']       = $methodData->key;
        $data['salt']      = $methodData->salt;
        $data['txnid']     = unique_code();
        $data['email']     = '';
        $data['firstname'] = '';
        Session::put('amount', $request->amount);
        Session::put('total_amount', $request->total_amount);
        Session::put('merchant', $request->merchant);
        Session::put('item_name', $request->item_name);
        Session::put('order_no', $request->order_no);
        Session::save();
        return view('merchantPayment.payumoney_form', $data);
        
    }

    public function payuPaymentSuccess(Request $request)
    {
        if (session('payumoney_currency_code') !== 'INR') {
            $this->helper->one_time_message('error', __('PayUMoney only supports Indian Rupee(INR)'));
            return redirect('payment/fail');
        } 

        $paymentMethod = PaymentMethod::whereName('PayUmoney')->first(['id']);
        $currency      = Currency::where(['code' => session('payumoney_currency_code')])->first(['id', 'code']);

        $uniqueCode = unique_code();
        $amount     = Session::get('amount');
        $merchant   = Session::get('merchant');

        $merchantInfo = Merchant::with('merchant_group:id,fee_bearer')->find($merchant, ['id', 'user_id', 'fee', 'merchant_group_id']);

        if (!$merchantInfo) {
            $this->helper->one_time_message('error', __('Merchant not found!'));
            return redirect('payment/fail');
        }

        //Deposit + Merchant Fee 
        $feesLimit = $this->merchantService->checkMerchantPaymentFeesLimit($currency->id, $paymentMethod->id, $amount, $merchantInfo->fee);

        if ($request->all()) {
            try {
                DB::beginTransaction();

                //Merchant payment
                $merchantPayment = $this->merchantService->makeMerchantPayment($request, $merchantInfo, $feesLimit, $currency->id, $uniqueCode, $paymentMethod->id, $paymentMethod->id);

                //Merchant Transaction
                $this->merchantService->makeMerchantTransaction($request, $merchantInfo, $feesLimit, $currency->id, $uniqueCode, $merchantPayment, $paymentMethod->id);

                //Wallet
                $merchantWallet = Wallet::where(['user_id' => $merchantInfo->user_id, 'currency_id' => $currency->id])->first(['id', 'balance']);

                //merchant wallet create or update
                $this->merchantService->createOrUpdateMerchantWallet($request->amount, $merchantInfo, $currency->id, $feesLimit['totalFee'], $merchantWallet);

                DB::commit();

                // Send mail to admin
                (new NotifyAdminOnPaymentMailService())->send($merchantPayment, ['type' => 'payment', 'medium' => 'email', 'fee_bearer' => $merchantInfo?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

                // Send mail to merchant
                (new NotifyMerchantOnPaymentMailService())->send($merchantPayment, ['fee_bearer' => $merchantInfo?->merchant_group?->fee_bearer, 'fee' => $feesLimit['totalFee']]);

                clearActionSession();
                return redirect('payment/success');
            } catch (Exception $e) {
                DB::rollBack();
                clearActionSession();
                $this->helper->one_time_message('error', $e->getMessage());
                return redirect('payment/fail');
            }
        } else {
            clearActionSession();
            return redirect('payment/fail');
        }
        
    }

    public function merchantPayumoneyPaymentFail(Request $request)
    {
        if ($request['status'] == 'failure') {
            clearActionSession();
            $this->helper->one_time_message('error', __('You have cancelled your payment'));
            return redirect('login');
        }
    }
    /*PayUMoney Merchant Payment Ends*/

    public function success()
    {
        $data = [
            'amount' => Session::get('merchant_amount'),
            'currency_code' => Session::get('merchant_currency_code'),
        ];
        return view('merchantPayment.success', $data);
    }

    public function fail()
    {
        return view('merchantPayment.fail');
    }
}
