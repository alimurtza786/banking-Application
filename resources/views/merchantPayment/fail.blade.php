@extends('merchantPayment.layouts.app')

@section('content')

@include('frontend.layouts.common.alert')
<div class="section-payment">
    <div class="payment-main-module">
        <div class="d-flex justify-content-center align-items-center">
            <div class="status-logo">
                <img src="{{ asset(image('', 'fail')) }}">
            </div>
        </div>
        <h3>{{ __('Sorry!') }}</h3>
        <p>{{ __('Merchant Payment Unsuccessful') }}</p>
        @isset($message)
            <p class="text-danger"> {{ $message }}</p>
        @endisset
        <div class="btn-tryagin d-flex justify-content-center align-items-center">
            <a href="{{ url('login') }}" class="btn btn-lg btn-light">{{ __('Login') }}</a>
        </div>
    </div>
</div>
@endsection