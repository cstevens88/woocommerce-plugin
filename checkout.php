<?php
/*
Plugin Name: Bitpay Woocommerce
Plugin URI: http://www.bitpay.com
Description: This plugin adds the Bitpay payment gateway to your Woocommerce plugin.  Woocommerce is required.
Version: 1.2
Author: Japhet Stevens, Rich Morgan
Author URI: http://www.bitpay.com
License:

 * The MIT License (MIT)
 * 
 * Copyright (c) 2011-2014 BitPay
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
*/


if (!function_exists('nn_active_nw_plugins')) {
  function nn_active_nw_plugins() {

        if (!is_multisite())
            return false;

        $nn_activePlugins = (get_site_option('active_sitewide_plugins')) ? array_keys(get_site_option('active_sitewide_plugins')) : array();
        return $nn_activePlugins;

  }
}


/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) || in_array('woocommerce/woocommerce.php', (array) nn_active_nw_plugins()) ) 
{
	function bplog($contents)
	{
		error_log($contents);
	}

	function declareWooBitpay() 
	{
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) 
			return;

		class WC_Bitpay extends WC_Payment_Gateway 
		{
		
			public function __construct() 
			{
				$this->id = 'bitpay';
				$this->icon = plugin_dir_url(__FILE__).'bitpay.png';
				$this->has_fields = false;
			 
				// Load the form fields.
				$this->init_form_fields();
			 
				// Load the settings.
				$this->init_settings();
			 
				// Define user set variables
				$this->title = $this->settings['title'];
				$this->description = $this->settings['description'];
			 
				// Actions
				add_action('woocommerce_update_options_payment_gateways_'.$this->id, array(&$this, 'process_admin_options'));
				//add_action('woocommerce_thankyou_cheque', array(&$this, 'thankyou_page'));
			 
				// Customer Emails
				add_action('woocommerce_email_before_order_table', array(&$this, 'email_instructions'), 10, 2);
			}
			
			function init_form_fields() 
			{
				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woothemes' ),
						'type' => 'checkbox',
						'label' => __( 'Enable Bitpay Payment', 'woothemes' ),
						'default' => 'yes'
					),
					'title' => array(
						'title' => __( 'Title', 'woothemes' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
						'default' => __( 'Bitcoins', 'woothemes' )
					),
					'description' => array(
						'title' => __( 'Customer Message', 'woothemes' ),
						'type' => 'textarea',
						'description' => __( 'Message to explain how the customer will be paying for the purchase.', 'woothemes' ),
						'default' => 'You will be redirected to bitpay.com to complete your purchase.'
					),
					'apiKey' => array(
						'title' => __('API Key', 'woothemes'),
						'type' => 'text',
						'description' => __('Enter the API key you created at bitpay.com'),
					),
					'transactionSpeed' => array(
						'title' => __('Transaction Speed', 'woothemes'),
						'type' => 'select',
						'description' => 'Choose a transaction speed.  For details, see the API documentation at bitpay.com',
						'options' => array(
							'high' => 'High',
							'medium' => 'Medium',
							'low' => 'Low',
						),
						'default' => 'high',
					),
					'fullNotifications' => array(
						'title' => __('Full Notifications', 'woothemes'),
						'type' => 'checkbox',
						'description' => 'Yes: receive an email for each status update on a payment.  No: receive an email only when payment is confirmed.',
						'default' => 'no',
					),
					'fbaEnabled' => array(
						'title' => __('Fullfullment By Amazon Enabled', 'woothemes'),
						'type' => 'checkbox',
						'description' => 'FBA account requred.  Fill in account info at ./fba_options.php.',
						'default' => 'no',
					),
				);
			}
				
			public function admin_options() {
				?>
				<h3><?php _e('Bitcoin Payment', 'woothemes'); ?></h3>
				<p><?php _e('Allows bitcoin payments via bitpay.com.', 'woothemes'); ?></p>
				<table class="form-table">
				<?php
					// Generate the HTML For the settings form.
					$this->generate_settings_html();
				?>
				</table>
				<?php
			} // End admin_options()
			
			public function email_instructions( $order, $sent_to_admin ) {
				return;
			}

			function payment_fields() {
				if ($this->description) echo wpautop(wptexturize($this->description));
			}
			 
			function thankyou_page() {
				if ($this->description) echo wpautop(wptexturize($this->description));
			}

			function process_payment( $order_id ) {
				require 'bp_lib.php';
				
				global $woocommerce, $wpdb;

				$order = new WC_Order( $order_id );

				// Mark as on-hold (we're awaiting the coins)
				$order->update_status('on-hold', __('Awaiting payment notification from bitpay.com', 'woothemes'));
				
				// invoice options
				$vcheck = explode('.',WC_VERSION);
                                if(trim($vcheck[0]) >= '2' && trim($vcheck[1]) >= '1')
                                    $thanks_link = $this->get_return_url($this->order);
                                else
                                    $thanks_link =  get_permalink(get_option('woocommerce_thanks_page_id'));

				$redirect = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, $thanks_link));
				
				$notificationURL = get_option('siteurl')."/?bitpay_callback=1";
				
				$currency = get_woocommerce_currency();
				
				
				$prefix = 'billing_';
				$options = array(
					'apiKey' => $this->settings['apiKey'],
					'transactionSpeed' => $this->settings['transactionSpeed'],
					'currency' => $currency,
					'redirectURL' => $redirect,
					'notificationURL' => $notificationURL,
					'fullNotifications' => ($this->settings['fullNotifications'] == 'yes') ? true : false,
					'buyerName' => $order->{$prefix.first_name}.' '.$order->{$prefix.last_name},
					'buyerAddress1' => $order->{$prefix.address_1},
					'buyerAddress2' => $order->{$prefix.address_2},
					'buyerCity' => $order->{$prefix.city},
					'buyerState' => $order->{$prefix.state},
					'buyerZip' => $order->{$prefix.postcode},
					'buyerCountry' => $order->{$prefix.country},
					'buyerPhone' => $order->billing_phone,
					'buyerEmail' => $order->billing_email,
					);
					
				if (strlen($order->{$prefix.company}))
					$options['buyerName'] = $order->{$prefix.company}.' c/o '.$options['buyerName'];
				
				foreach(array('buyerName', 'buyerAddress1', 'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 'buyerCountry', 'buyerPhone', 'buyerEmail') as $trunc)
					$options[$trunc] = substr($options[$trunc], 0, 100); // api specifies max 100-char len

				$invoice = bpCreateInvoice($order_id, $order->order_total, $order_id, $options );
				if (isset($invoice['error']))
				{
					$order->add_order_note(var_export($invoice['error'], true));
					$woocommerce->add_error(__('Error creating BitPay invoice.  Please try again or try another payment method.'));
				}
				else
				{
					$woocommerce->cart->empty_cart();
				
					return array(
						'result'    => 'success',
						'redirect'  => $invoice['url'],
					);
				}			 
			}
		}
	}

	include plugin_dir_path(__FILE__).'callback.php';

	function add_bitpay_gateway( $methods ) {
		$methods[] = 'WC_Bitpay'; 
		return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'add_bitpay_gateway' );

	add_action('plugins_loaded', 'declareWooBitpay', 0);
	
	
}
