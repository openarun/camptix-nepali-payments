/**
 * Khalti Payment Redirect Script
 * Handles redirecting users to the Khalti payment page
 */
(function() {
    if (typeof camptixKhaltiData !== 'undefined' && camptixKhaltiData.paymentUrl) {
        window.location.href = camptixKhaltiData.paymentUrl;
    }
})();