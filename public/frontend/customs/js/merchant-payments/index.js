'use strict';

$(document).ready(function() {
    $('#paymentMethodForm').on('submit', function() {
        $("#paymentMethodSubmitBtn").attr("disabled", true);
        $(".spinner").removeClass('d-none');
        $("#paymentMethodSubmitBtnText").text(paymentMethodSubmitBtnText);
    });
});