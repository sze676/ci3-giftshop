(function () {
    'use strict';

    var priceInput = document.getElementById('product-price');
    var markupInput = document.getElementById('product-markup');
    var preview = document.getElementById('selling-price-preview');
    if (!priceInput || !markupInput || !preview) return;

    var formatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function parseAmount(value) {
        if (!/^[0-9]{1,8}(?:[.][0-9]{1,2})?$/.test(value)) return null;
        var parts = value.split('.');
        return Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2));
    }

    function updatePreview() {
        var baseCents = parseAmount(priceInput.value);
        var markupPoints = parseAmount(markupInput.value || '0');
        if (baseCents === null || markupPoints === null) {
            preview.textContent = 'Enter a valid price and markup.';
            return;
        }

        var factor = 10000 + markupPoints;
        var maxCents = 9999999999;
        if (baseCents > 0 && factor > Math.floor((maxCents * 10000 + 4999) / baseCents)) {
            preview.textContent = 'Selling price exceeds ₱99,999,999.99.';
            return;
        }

        var totalCents = Math.floor((baseCents * factor + 5000) / 10000);
        preview.textContent = formatter.format(totalCents / 100);
    }

    priceInput.addEventListener('input', updatePreview);
    markupInput.addEventListener('input', updatePreview);
    updatePreview();
})();
