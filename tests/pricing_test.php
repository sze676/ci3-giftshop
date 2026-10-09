<?php
// Run from the repository root: php tests/pricing_test.php
define('BASEPATH', dirname(__DIR__) . '/system/');
require dirname(__DIR__) . '/application/helpers/pricing_helper.php';

$cases = array(
	array('100', '20', '120.00'),
	array('100.00', '0', '100.00'),
	array('100', '12.5', '112.50'),
	array('100', '150', '250.00'),
	array('0', '20', '0.00'),
	array('1.01', '50', '1.52'),
	array('0.05', '10', '0.06'),
	array('100.05', '10', '110.06'),
	array('99.99', '12.5', '112.49'),
	array('19.99', '7.5', '21.49'),
	array('1299.00', '20', '1558.80'),
	array('99999999.99', '0', '99999999.99'),
	array('99999999.99', '0.01', NULL),
	array('0.01', '99999999.99', '10000.01'),
	array('0', '99999999.99', '0.00'),
	array('-100', '20', NULL),
	array('100', '-20', NULL),
	array('1.001', '20', NULL),
	array('100', '20.001', NULL),
	array('100000000', '0', NULL),
	array('100', '100000000', NULL),
	array('1e2', '20', NULL),
	array('100', 'NaN', NULL),
	array('INF', '20', NULL),
	array('', '20', NULL),
	array('100', '', NULL),
	array('100', array('20'), NULL),
	array(NULL, '20', NULL)
);

foreach ($cases as $index => $case) {
	$actual = giftshop_price_with_markup($case[0], $case[1]);
	if ($actual !== $case[2]) {
		fwrite(STDERR, 'FAIL case ' . ($index + 1) . ': expected ' . var_export($case[2], TRUE) . ', got ' . var_export($actual, TRUE) . PHP_EOL);
		exit(1);
	}
}

echo count($cases) . ' pricing checks passed.' . PHP_EOL;
