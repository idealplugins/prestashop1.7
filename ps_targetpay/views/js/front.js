jQuery(document).ready(function () {
    jQuery('body').on('change', 'input[type=radio][name=payment_option_IDE]', function () {
        $(this).parents('.additional-information').next('.js-payment-option-form').find('input[name=option]').val($(this).val());
        return false;
    });
    jQuery('body').on('change', 'input[name=payment_option_DEB]', function () {
        $(this).parents('.additional-information').next('.js-payment-option-form').find('input[name=option]').val($(this).val());
        return false;
    });
});
