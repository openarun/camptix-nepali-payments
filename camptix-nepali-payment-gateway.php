<?php

/**
 * Plugin Name: Camptix Nepali Payments
 * Description: Nepali Payment Gateways for CampTix
 * Author: Arun Kumar Pariyar
 * Author URI: http://github.com/openarun
 * Version: 0.0.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: camptix-nepali-payments
 */


if (!defined('ABSPATH')) exit; // Exit if accessed directly

class CampTix_Nepali_Payments
{

	function __construct()
	{
		add_action('plugins_loaded', [$this, 'init_plugin']);
		add_action('camptix_load_addons', [$this, 'load_addons']);
		add_filter('camptix_currencies', [$this, 'add_currency']);
	}

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init_plugin()
	{
		$this->includes();
	}

	/**
	 * Include files
	 *
	 * @return void
	 */
	public function includes()
	{

		if (! class_exists('CampTix_Payment_Method')) {
			return;
		}

		if (!class_exists('CampTix_Nepali_Payment_Method')) {
			require_once __DIR__ . '/includes/class-camptix-khalti-payment.php';
		}
	}

	/**
	 * Load the add-ons
	 *
	 * @return void
	 */
	public function load_addons()
	{
		camptix_register_addon('CampTix_Khalti_Payment_Method');
	}

	/**
	 * Add NPR currency
	 *
	 * @param array $currencies
	 *
	 * @return array
	 */
	public function add_currency($currencies)
	{
		$currencies['NPR'] = [
			'label'         => __('NPR', 'nepali-payments-camptix'),
			'format'        => 'NPR %s',
			'decimal_point' => 2,
		];

		return $currencies;
	}
}


new CampTix_Nepali_Payments();
