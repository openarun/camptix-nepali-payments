<?php
/**
 * Plugin Name: CampTix Nepali Payments
 * Description: Nepali Payment Gateways for CampTix - Accept payments in Nepali Rupees (NPR)
 * Author: Arun Kumar Pariyar
 * Author URI: http://github.com/openarun
 * Version: 1.0.0
 * Requires at least: 3.5
 * Tested up to: 6.8
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: camptix-nepali-payments
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class CampTix_Nepali_Payments {

	/**
	 * Plugin instance.
	 *
	 * @var CampTix_Nepali_Payments
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return CampTix_Nepali_Payments
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
		add_action( 'camptix_load_addons', array( $this, 'load_addons' ) );
		add_filter( 'camptix_currencies', array( $this, 'add_currency' ) );
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->includes();
	}

	/**
	 * Include required files.
	 *
	 * @return void
	 */
	public function includes() {
		if ( ! class_exists( 'CampTix_Payment_Method' ) ) {
			return;
		}

		if ( ! class_exists( 'CampTix_Nepali_Payment_Method' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-camptix-khalti-payment.php';
		}
	}

	/**
	 * Load the add-ons.
	 *
	 * @return void
	 */
	public function load_addons() {
		if ( function_exists( 'camptix_register_addon' ) ) {
			camptix_register_addon( 'CampTix_Khalti_Payment_Method' );
		}
	}

	/**
	 * Add NPR currency.
	 *
	 * @param array $currencies List of currencies.
	 * @return array
	 */
	public function add_currency( $currencies ) {
		$currencies['NPR'] = array(
			'label'         => __( 'NPR', 'camptix-nepali-payments' ),
			'format'        => 'NPR %s',
			'decimal_point' => 2,
		);

		return $currencies;
	}
}

// Initialize the plugin.
CampTix_Nepali_Payments::get_instance();
