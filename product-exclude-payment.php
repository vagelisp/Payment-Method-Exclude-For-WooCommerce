<?php
/*
Plugin Name: Payment Method Exclude For WooCommerce
Description: Exclude specific payment methods for selected WooCommerce products and product categories.
Version: 1.0
Author: Vagelis Papaioannou
Text Domain: wc-product-exclude-payment
*/

namespace WCProductExcludePayment;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Load plugin text domain
add_action('plugins_loaded', 'WCProductExcludePayment\load_textdomain');
function load_textdomain()
{
	load_plugin_textdomain('wc-product-exclude-payment', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// Check for WooCommerce dependency
function check_dependencies()
{
	include_once(ABSPATH . 'wp-admin/includes/plugin.php');
	if (!is_plugin_active('woocommerce/woocommerce.php')) {
		add_action('admin_notices', __NAMESPACE__ . '\missing_wc_notice');
	}
}

function missing_wc_notice()
{
	echo '<div class="error"><p>' . esc_html('WooCommerce Product Exclude Payment requires WooCommerce to be installed and active.', 'wc-product-exclude-payment') . '</p></div>';
}

add_action('plugins_loaded', 'WCProductExcludePayment\check_dependencies', 10);

/**
 * WooCommerce Product Exclude Payment main class
 */
class WC_Product_Exclude_Payment
{
	public function __construct()
	{
		// Add product options
		add_action('woocommerce_product_options_general_product_data', array($this, 'wc_product_exclude_payment_options'));

		// Save product options
		add_action('woocommerce_process_product_meta', array($this, 'wc_product_exclude_payment_save'));

		// Filter available payment gateways
		add_filter('woocommerce_available_payment_gateways', array($this, 'wc_product_exclude_payment_gateways'));

		// Add product category options
		add_action('product_cat_add_form_fields', array($this, 'wc_product_category_exclude_payment_options'));
		add_action('product_cat_edit_form_fields', array($this, 'wc_product_category_exclude_payment_options'));

		// Save product category options
		add_action('create_product_cat', array($this, 'wc_product_category_exclude_payment_save'));
		add_action('edit_product_cat', array($this, 'wc_product_category_exclude_payment_save'));
	}

	/**
	 * Add product exclude payment options
	 */
	public function wc_product_exclude_payment_options()
	{
		global $post;

		$product_excluded_payment_methods = get_post_meta($post->ID, '_product_excluded_payment_methods', true);

		echo '<div class="options_group">';

		$this->woocommerce_wp_select_multiple(array(
			'id' => '_product_excluded_payment_methods',
			'label' => __('Excluded Payment Methods', 'woocommerce'),
			'options' => $this->wc_get_payment_gateway_ids(),
			'value' => empty($product_excluded_payment_methods) ? array() : $product_excluded_payment_methods,
			'desc_tip' => true,
			'description' => __('Select the payment methods to be excluded for this product.', 'woocommerce'),
		));

		echo '</div>';
	}

	/**
	 * Save product exclude payment options
	 */
	public function wc_product_exclude_payment_save($post_id)
	{
		$product_excluded_payment_methods = isset($_POST['_product_excluded_payment_methods']) ? $_POST['_product_excluded_payment_methods'] : array();
		update_post_meta($post_id, '_product_excluded_payment_methods', $product_excluded_payment_methods);
	}

	/**
	 * Add product category exclude payment options
	 */
	public function wc_product_category_exclude_payment_options($term)
	{
		$term_id = $term->term_id;
		$category_excluded_payment_methods = get_term_meta($term_id, '_category_excluded_payment_methods', true);
		echo '<tr class="form-field">';
		echo '<th scope="row" valign="top"><label>' . esc_html__('Excluded Payment Methods', 'wc-product-exclude-payment') . '</label></th>';
		echo '<td>';
		$this->woocommerce_wp_select_multiple(array(
			'id' => '_category_excluded_payment_methods',
			'label' => null,
			'options' => $this->wc_get_payment_gateway_ids(),
			'value' => empty($category_excluded_payment_methods) ? array() : $category_excluded_payment_methods,
		));
		echo '<p class="description">' . esc_html__('Select the payment methods to be excluded for all products in this category.', 'wc-product-exclude-payment') . '</p>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Save product category exclude payment options
	 */
	public function wc_product_category_exclude_payment_save($term_id)
	{
		$category_excluded_payment_methods = isset($_POST['_category_excluded_payment_methods']) ? $_POST['_category_excluded_payment_methods'] : array();
		update_term_meta($term_id, '_category_excluded_payment_methods', $category_excluded_payment_methods);
	}

	/**
	 * Exclude payment gateways for specific products and product categories
	 */
	public function wc_product_exclude_payment_gateways($available_gateways)
	{
		if (!is_checkout()) {
			return $available_gateways;
		}

		$cart_contents = WC()->cart->get_cart_contents();
		$excluded_gateways = array();

		foreach ($cart_contents as $cart_item_key => $cart_item) {
			$product_excluded_payment_methods = get_post_meta($cart_item['product_id'], '_product_excluded_payment_methods', true);
			$product_categories = wp_get_post_terms($cart_item['product_id'], 'product_cat', array('fields' => 'ids'));

			if (!empty($product_excluded_payment_methods) && is_array($product_excluded_payment_methods)) {
				$excluded_gateways = array_merge($excluded_gateways, $product_excluded_payment_methods);
			}

			foreach ($product_categories as $category_id) {
				$category_excluded_payment_methods = get_term_meta($category_id, '_category_excluded_payment_methods', true);

				if (!empty($category_excluded_payment_methods) && is_array($category_excluded_payment_methods)) {
					$excluded_gateways = array_merge($excluded_gateways, $category_excluded_payment_methods);
				}
			}
		}

		if (!empty($excluded_gateways)) {
			foreach ($excluded_gateways as $gateway_id) {
				if (isset($available_gateways[$gateway_id])) {
					unset($available_gateways[$gateway_id]);
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Custom woocommerce_wp_select_multiple function
	 */
	public function woocommerce_wp_select_multiple($field)
	{
		$field['class'] = isset($field['class']) ? $field['class'] : 'select short';
		$field['wrapper_class'] = isset($field['wrapper_class']) ? $field['wrapper_class'] : '';
		$field['value'] = isset($field['value']) ? $field['value'] : array();
		$field['name'] = isset($field['name']) ? $field['name'] : $field['id'];
		$field['desc_tip'] = isset($field['desc_tip']) ? $field['desc_tip'] : false;

		echo '<p class="' . esc_attr($field['wrapper_class']) . '">
		<label for="' . esc_attr($field['id']) . '">' . wp_kses_post($field['label']) . '</label>';

		if (!empty($field['description']) && $field['desc_tip']) {
			echo wc_help_tip($field['description']);
		}

		echo '<select
		id="' . esc_attr($field['id']) . '"
		name="' . esc_attr($field['name']) . '[]"
		class="' . esc_attr($field['class']) . '"
		multiple="multiple">';

		foreach ($field['options'] as $key => $value) {
			echo '<option value="' . esc_attr($key) . '"' . (in_array($key, $field['value']) ? 'selected="selected"' : '') . '>' . esc_html($value) . '</option>';
		}

		echo '</select>';

		if (!empty($field['description']) && !$field['desc_tip']) {
			echo '<span class="description">' . wp_kses_post($field['description']) . '</span>';
		}

		echo '</p>';
	}

	/**
	 * Get payment gateway IDs
	 */
	private function wc_get_payment_gateway_ids()
	{
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateway_ids = array();

		foreach ($gateways as $gateway) {
			$gateway_ids[$gateway->id] = $gateway->get_title();
		}

		return $gateway_ids;
	}
}

new WC_Product_Exclude_Payment();
