(function () {
    'use strict';

    var STORAGE_KEY = 'pitchbar_cart_state';

    function readCart() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);

            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function writeCart(state) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch {
            // localStorage may be disabled (private mode); silent no-op.
        }
    }

    function clearCart() {
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch {
            // ignore
        }
    }

    function stamp() {
        var existing = readCart() || { items: 0 };
        writeCart({
            items: typeof existing.items === 'number' && existing.items > 0
                ? existing.items + 1
                : 1,
            timestamp: Date.now(),
        });
    }

    function decrement() {
        var existing = readCart();

        if (!existing || typeof existing.items !== 'number') {
            return;
        }

        var remaining = existing.items - 1;

        if (remaining <= 0) {
            clearCart();

            return;
        }

        writeCart({ items: remaining, timestamp: Date.now() });
    }

    function onCheckoutPage() {
        var path = window.location.pathname || '';
        var checkoutMarkers = [
            '/checkout',
            '/order-received',
            '/order-pay',
            '/thank-you',
        ];

        for (var i = 0; i < checkoutMarkers.length; i++) {
            if (path.indexOf(checkoutMarkers[i]) !== -1) {
                return true;
            }
        }

        return false;
    }

    if (onCheckoutPage()) {
        clearCart();

        return;
    }

    // WooCommerce emits these jQuery events on every cart mutation.
    // Mirror them into localStorage so the widget's abandoned_cart
    // trigger can read the timestamp + item count without a server
    // round-trip.
    if (typeof window.jQuery === 'function') {
        window.jQuery(document.body).on('added_to_cart', stamp);
        window.jQuery(document.body).on('removed_from_cart', decrement);
    }

    // Fallback for sites without jQuery on the front end (rare on Woo,
    // but bullet-proofing): a DOM click on any .add_to_cart_button.
    document.addEventListener(
        'click',
        function (event) {
            var target = event.target;

            if (!target || !target.classList) {
                return;
            }

            if (
                target.classList.contains('add_to_cart_button') ||
                target.classList.contains('single_add_to_cart_button')
            ) {
                stamp();
            }
        },
        true
    );
})();
