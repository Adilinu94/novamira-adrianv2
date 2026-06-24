<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$files = [ __DIR__ . '/class-woocommerce-check-setup.php' ];
foreach ( $files as $f ) {
	if ( file_exists( $f ) ) {
		require_once $f;
	}
}

if ( class_exists( 'Novamira\AdrianV2\Abilities\WooCommerce\WooCommerce_Check_Setup' )
	&& method_exists( 'Novamira\AdrianV2\Abilities\WooCommerce\WooCommerce_Check_Setup', 'register' ) ) {
	\Novamira\AdrianV2\Abilities\WooCommerce\WooCommerce_Check_Setup::register();
}
