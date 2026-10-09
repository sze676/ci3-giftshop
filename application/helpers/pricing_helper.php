<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Apply a one-time markup and round half up to cents.
 * Both amounts accept up to eight whole digits and two decimal places.
 * NULL means invalid input or a total beyond products.price DECIMAL(10,2).
 */
function giftshop_price_with_markup($base_price, $markup_percent)
{
	$amounts = array();
	foreach (array($base_price, $markup_percent) as $value) {
		if ( ! is_string($value) || ! preg_match('/^[0-9]{1,8}(?:[.][0-9]{1,2})?$/D', $value)) {
			return NULL;
		}

		$parts = explode('.', $value);
		$amounts[] = ((int) $parts[0] * 100) + (int) str_pad(isset($parts[1]) ? $parts[1] : '', 2, '0');
	}

	$base_cents = $amounts[0];
	$factor = 10000 + $amounts[1];
	$max_cents = 9999999999;

	// Check the rounded total before multiplying, keeping integer math exact.
	if ($base_cents > 0 && $factor > intdiv($max_cents * 10000 + 4999, $base_cents)) {
		return NULL;
	}

	$total_cents = intdiv($base_cents * $factor + 5000, 10000);
	return sprintf('%d.%02d', intdiv($total_cents, 100), $total_cents % 100);
}
