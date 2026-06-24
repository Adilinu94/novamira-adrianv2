<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/woocommerce-check-setup
 *
 * Read-only probe of the WooCommerce environment.
 * Reports: active, version, currency, shop pages, active payment gateways,
 * shipping zones, tax settings, and detected configuration issues.
 *
 * @since 1.8.0
 */
final class WooCommerce_Check_Setup {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/woocommerce-check-setup',
			[
				'label'       => 'Check WooCommerce Setup',
				'description' => 'Reports the WooCommerce environment: active state, version, currency, shop/cart/checkout/account page IDs, active payment gateways, shipping zones count, tax status, and detected configuration issues. Read-only.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'active'   => [ 'type' => 'boolean' ],
						'version'  => [ 'type' => [ 'string', 'null' ] ],
						'store'    => [ 'type' => 'object' ],
						'pages'    => [ 'type' => 'object' ],
						'payment'  => [ 'type' => 'object' ],
						'shipping' => [ 'type' => 'object' ],
						'issues'   => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute( $input = null ): array {
		$active  = class_exists( 'WooCommerce' );
		$version = $active ? ( defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown' ) : null;
		$issues  = [];

		if ( ! $active ) {
			return [
				'active'   => false,
				'version'  => null,
				'store'    => [],
				'pages'    => [],
				'payment'  => [],
				'shipping' => [],
				'issues'   => [ 'WooCommerce is not active.' ],
			];
		}

		// Store basics.
		$store = [
			'currency'         => get_option( 'woocommerce_currency', '' ),
			'currency_pos'     => get_option( 'woocommerce_currency_pos', 'left' ),
			'price_decimals'   => (int) get_option( 'woocommerce_price_num_decimals', 2 ),
			'calc_taxes'       => get_option( 'woocommerce_calc_taxes', 'no' ) === 'yes',
			'enable_reviews'   => get_option( 'woocommerce_enable_reviews', 'yes' ) === 'yes',
			'manage_stock'     => get_option( 'woocommerce_manage_stock', 'yes' ) === 'yes',
		];

		// Key WC pages.
		$page_ids = [
			'shop'     => (int) get_option( 'woocommerce_shop_page_id' ),
			'cart'     => (int) get_option( 'woocommerce_cart_page_id' ),
			'checkout' => (int) get_option( 'woocommerce_checkout_page_id' ),
			'account'  => (int) get_option( 'woocommerce_myaccount_page_id' ),
		];

		$pages = [];
		foreach ( $page_ids as $key => $id ) {
			$pages[ $key ] = [
				'post_id' => $id,
				'ok'      => $id > 0 && get_post_status( $id ) === 'publish',
			];
			if ( $id === 0 ) {
				$issues[] = "WooCommerce {$key} page is not configured.";
			} elseif ( get_post_status( $id ) !== 'publish' ) {
				$issues[] = "WooCommerce {$key} page (ID {$id}) is not published.";
			}
		}

		// Payment gateways — available only when WC is fully bootstrapped.
		$payment = [ 'gateways' => [], 'count_active' => 0 ];
		if ( function_exists( 'WC' ) && WC()->payment_gateways instanceof \WC_Payment_Gateways ) {
			foreach ( WC()->payment_gateways->get_available_payment_gateways() as $id => $gw ) {
				$payment['gateways'][] = [
					'id'      => $id,
					'title'   => $gw->get_title(),
					'enabled' => $gw->is_available(),
				];
			}
			$payment['count_active'] = count( $payment['gateways'] );
		}
		if ( $payment['count_active'] === 0 ) {
			$issues[] = 'No active payment gateways — customers cannot check out.';
		}

		// Shipping zones.
		$shipping = [ 'zones_count' => 0, 'has_rates' => false ];
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			$shipping['zones_count'] = count( $zones );
			foreach ( $zones as $zone ) {
				if ( ! empty( $zone['shipping_methods'] ) ) {
					$shipping['has_rates'] = true;
					break;
				}
			}
		}
		if ( ! $shipping['has_rates'] && $store['manage_stock'] ) {
			$issues[] = 'No shipping rates configured — physical products may not be purchasable.';
		}

		return [
			'active'   => true,
			'version'  => $version,
			'store'    => $store,
			'pages'    => $pages,
			'payment'  => $payment,
			'shipping' => $shipping,
			'issues'   => $issues,
		];
	}
}
