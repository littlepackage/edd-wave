<?php
/**
 * Plugin Name: Easy Digital Downloads Wave
 * Description: This amazing plugin moves a successful EDD purchase into Wave Apps accounting, with a payment entry under Accounting -> Transactions. Custom for Caroline, by Caroline because she loves herself
 *
 * Version: 1.9
 * Author: Little Package
 * Text Domain: edd-wave
 *
 * Easy Digital Downloads Wave
 * Copyright: (c) 2022-2023 Little Package
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * 1.1 - 3 Nov 2021, rework using the API callback from Stripe
 * 1.2 - 19 June 2022, check for Paypal and abort if PayPal (stopgap until payPal built back in :\ )
 * 1.3 - 3 July 2022, PayPal built back in, sorta. (Way simplified.)
 * 1.4 - 15 Dec 2022, Gift Wrapper integrated (for application on GiftWrapper.app
comment out date_default_timezone_set()
 * 1.5 - 22 Dec 2022 - It wasn't set to actually work with PayPal! Fuck I'm tired of data entry
 * 1.6 - make $last_called work again and add PayPal fx to EDD after order cron fx
 * 1.7 - start to dig into EDD's PayPal API to get Paypal transaction details for accounting
 * 1.8 - Finally, fully integrate EDD\Gateways\PayPal\API
 * 1.9 - Develop full admin settings panel, map accounts so that others can use this, too
 */

use EDD\Gateways\PayPal;
use EDD\Gateways\PayPal\AccountStatusValidator;
use EDD\Gateways\PayPal\API;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

if ( ! class_exists( 'Sagehen_EDD_Wave' ) ) :

	class Sagehen_EDD_Wave {

		private $settings;

		private $business_id;

		private $api_key;

		/**
		 * Single instance of the Sagehen_EDD_Wave class
		 *
		 * @var Sagehen_EDD_Wave
		 */
		protected static $_instance = null;

		/**
		 * Instantiator
		 */
		public static function instance() {

			if ( ! isset( self::$_instance ) && ! ( self::$_instance instanceof Sagehen_EDD_Wave ) ) {
				self::$_instance = new Sagehen_EDD_Wave;
			}
			return self::$_instance;

		}

		/**
		 * Constructor
		 */
		public function __construct() {

			if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
				error_log( 'EDD + Wave: This plugin requires Easy Digital Downloads be installed and activated.' );
				return;
			}

			$this->define_constants();

			$this->includes();

			$this->settings = (array) get_option( 'edd_wave', [] );

			$this->business_id = $this->settings['business_id'];

			$this->api_key = $this->settings['api_key'];

			add_action( 'init', [ $this, 'init' ] );

			// Catch PayPal payment details via the EDD Gateway API
			add_action( 'edd_after_payment_actions', [ $this, 'edd_after_payment' ], 10, 3 );

			/**
			 * Allows further processing after a payment is created (notice the "S" after edd in the hook name)
			 *
			 * @since 2.7.0
			 *
			 * @param \EDD_Payment $payment EDD Payment.
			 * @param \Stripe\PaymentIntent|\Stripe\SetupIntent $intent Created Stripe Intent.
			 * Payment intent docs: https://stripe.com/docs/api/payment_intents
			 */
			add_action( 'edds_payment_complete', [ $this, 'edds_payment_complete' ], 15, 2 );

		}

		/**
		 * Define constants
		 *
		 */
		private function define_constants() {

			if ( ! defined( 'EDD_WAVE_PLUGIN_FILE' ) ) {
				define( 'EDD_WAVE_PLUGIN_FILE', __FILE__ );
			}
			if ( ! defined( 'EDD_WAVE_VERSION' ) ) {
				define( 'EDD_WAVE_VERSION', '1.8' );
			}

		}

		/**
		 * Include required files
		 *
		 */
		private function includes() {

			require_once __DIR__ . '/includes/class-edd-wave-settings.php';

		}

		/**
		 * Fire up the admin settings class
		 *
		 */
		public function init( $hook ) {

			if ( is_admin() ) {
				new EDD_Wave_Settings;
			}

		}

		/**
		 * Gather data after PayPal payment complete
		 * Runs off CRON
		 *
		 * @param object $payment
		 * @param object $customer
		 *
		 */
		public function edd_after_payment( $payment_id, $payment, $customer ) {

			$payment_method = edd_get_payment_gateway( $payment_id ); // 'stripe' or 'paypal_commerce'

			if ( 'stripe' === $payment_method ) { // should be 'paypal_commerce', not 'stripe'
				return;
			}

			$paypal_order_id = edd_get_payment_meta( $payment_id, 'paypal_order_id', true );

			try {

				$api = new PayPal\API();
				// https://developer.paypal.com/docs/api/orders/v2/#orders_get
				$response = $api->make_request( 'v2/checkout/orders/' . urlencode( $paypal_order_id ), [], [], 'GET' );

			} catch ( \Exception $e ) {

				error_log( 'EDD + Wave: PayPal\API exception message: ' . print_r( $e->message, true ));
				return;

			}

			if ( empty( $response->id ) || 'COMPLETED' !== $response->status ) {
				error_log( 'EDD + Wave: $response->id not found or $response->status not "COMPLETED"' );
				return;
			}

             // Unused
			// $this->country = $response->payment_source->paypal->address->country_code ?? '';
			// $this->order_id	= $payment_id;
			// $order 			= edd_get_order( $payment_id );


			if ( ! $response->purchase_units ) {
				error_log( 'EDD + Wave: Bad (unusable) response from PayPal API.' );
				return;
			}

error_log( 'EDD + Wave: PayPal API response: ' . print_r( $response, true ) );

			$line_items = [];
			/**
			 * Cart (download) details
			 * get array of lineItems for Wave API inputMoneyTransactionCreate
			 *
			 */
			foreach( $response->purchase_units as $index => $purchase_unit ) {


error_log( 'EDD + Wave: PayPal purchase unit: ' . print_r( $purchase_unit, true ) );

				// Total price after discounts/fees/taxes applied. In other words, the amount proposed TO PayPal
				$price = $purchase_unit->payments->captures->$index->seller_receivable_breakdown->gross_amount->value ?? false;

				if ( ! isset( $price ) ) {
					continue;
				}

                // custom id is actually the Order ID
                // @todo
				$item_id = $purchase_unit->custom_id ?? '';
				if ( empty( $item_id ) ) {
					continue;
				}

				$line_items[] = array(

					'accountId'	=> $this->getWaveAccount( $item_id ),
					'amount'	=> $price,
					'balance'	=> 'CREDIT'

				);

				$fee = $purchase_unit->payments->captures->$index->seller_receivable_breakdown->paypal_fee->value ?? null;
				if ( $fee ) {

					/**
					 * PayPal Merchant Account Fees (a debit)
					 */
					$line_items[] = array(
						'accountId'	=> $this->settings['paypal_fees_account_id'],
						'amount'	=> $fees,
						'balance'	=> 'DEBIT'
					);

				}

			}

			if ( empty( $line_items ) ) {
				return;
			}

			$order_total = edd_get_payment_amount( $payment_id ); // remember we DON'T TAX THE TAX.
			$order_total -= $fees; // (Order total should be item prices, plus taxes, minus merchant fees. This will reflect in anchor vs. line items)

error_log( 'EDD + Wave: PayPal line items: ' . print_r( $line_items, true ) );
return;

			// PayPal method ID passed:
			$this->createWaveTransaction( $payment, $this->settings['paypal_anchor_account_id'], $line_items, $net );

		}

		/**
		 * Gather data after Stripe Payment complete
		 *
		 * @param object $payment
		 * @param int $intent_id
		 * @return
		 */
		public function edds_payment_complete( $payment, $intent_id ) {

			// Don't run this more than once on the same payment
			if ( did_action( 'edds_payment_complete' ) > 1 ) {
				error_log( 'EDD + Wave: Stripe \'edds_payment_complete\' filter hook already run, so won\'t run it again' );
				return;
			}

			if ( ! function_exists( 'edds_api_request' ) ) {
				error_log( 'EDD + Wave: required function edds_api_request() does not exist.' );
				return;
			}

// error_log( 'EDD + Wave: $payment object: ' . print_r( $payment, true ) );

			// $order_subtotal = edd_get_payment_subtotal( $payment->ID );

			// $order_total = edd_get_payment_amount( $payment->ID ); // remember YOU DON'T TAX THE TAX.

			$user_info = edd_get_payment_meta_user_info( $payment->ID );
			$billing_address = ! empty( $user_info['address'] ) ? $user_info['address'] : array( 'line1' => '', 'line2' => '', 'city' => '', 'country' => '', 'state' => '', 'zip' => '' );
			$this->country = $billing_address['country'];
			$international = $this->country == 'US' ? false : true;


			// GET THE STRIPE INTENT FROM THE INTENT ID
			$intent = edds_api_request( 'PaymentIntent', 'retrieve', $intent_id );

			/**
			 * If payment wasn't successful, stop
			 */
			if ( 'succeeded' !== $intent->status ) {
				error_log( 'EDD + Wave: Payment not a success, aborting sending data to Wave.' );
				return;
			}

			$billing_country_code = current( $intent->charges->data )->billing_details->address->country;

			// GET FORMATTED BILLING COUNTRY NAME
			$billing_country = $this->getCountry( $billing_country_code );

			if ( ! class_exists( 'Stripe\Stripe' ) && defined( 'EDDS_PLUGIN_DIR' ) ) {
				require_once EDDS_PLUGIN_DIR . '/vendor/autoload.php';
			}

			$balance_trans_retrieved = false;
			// Try to get Balance Transaction from Stripe
			try {

				$balance_transaction = \Stripe\BalanceTransaction::retrieve( current( $intent->charges->data )->balance_transaction );
				// error_log( 'EDD + Wave Balance Transaction: ' . print_r( $balance_transaction, true ) );

				$amount	= floatval( $balance_transaction->amount / 100 );
				$merchant_fee = floatval( $balance_transaction->fee / 100 );
				$net	 = floatval( $balance_transaction->net / 100 );

				$balance_trans_retrieved = true;

				// Then if no luck, we try another method (using intent)
			} catch( Exception $e ) { // if no, use Intent

				error_log( 'Balance Transaction fetch failed. Using $intent->amount_received instead, which might fail due to merchant fee variability.' );

				$amount = floatval( $intent->amount_received / 100 );
				if ( 'US' === strtoupper( $billing_country_code ) ) {
					$merchant_fee = floatval( ( $amount * .029 ) + 0.30 );
				} else {
					$merchant_fee = floatval( ( $amount * .039 ) + 0.30 );
				} // there is also an additional 1% Stripe charge for currency conversions but those will probably be less common

				$net = '';
				// error_log( 'EDD + Wave: bad news, unable to retrieve Stripe BalanceTransaction data.' );
				// return;

			}

			/**
			 * LET'S START ADDING TO LINE ITEMS FOR THE WAVE ENTRY
			 * Line items are:
			 *		1. downloads,
			 *		2. fees,
			 *		3. discounts
			 *
			 * The "anchor" is an asset (cash and bank) or liability (credit card / LoC) : in our case, the merchant account
			 */

			if ( ! $payment->downloads ) {
				error_log( 'EDD + Wave: EDD payment strangely doesn\'t seem to include any downloads.' );
				return;
			}


// error_log( 'EDD + Wave: Payment downloads array: ' . print_r( $payment->downloads, true ) );

/*

// Looks like this for download with variation pricing
[0] => Array(
    [0] => Array
        (
            [id] => 2235
            [quantity] => 1
            [options] => Array
                (
                    [quantity] => 1
                    [price_id] => 1
                )

        )

    [1] => Array
        (
            [id] => 58392
            [quantity] => 1
            [options] => Array
                (
                    [quantity] => 1
                    [price_id] => 3
                )

        )

)

*/
			$total_fees = 0;
			$total_discounts = 0;
			$line_items = [];
			/**
			 * Get an array of line items "lineItems" for Wave API inputMoneyTransactionCreate
			 */
			foreach ( $payment->downloads as $download ) {

				$item_id = $download['id'] ?? '';

				if ( empty( $item_id ) ) {
					error_log( 'EDD + Wave: For some reason, a download was skipped in the foreach() loop, due to missing item ID.' );
					continue;
				}

				if ( isset( $download['options']['is_upgrade'] ) && true === $download['options']['is_upgrade'] ) {
					error_log( 'EDD + Wave: EDD Software License upgrade purchase not logged in Wave: ' . $payment->ID );
					continue;
				}

				// EDD price variation ID
				$price_id = $download['options']['price_id'] ?? '';


				/**
				 * Discounts (debit)
				 */
				$discount = $this->getEDDItemDiscount( $item_id, $payment->cart_details );

				if ( $discount ) {
					$total_discount = 0;
					// error_log( 'Discount: ' . print_r( $discount, true ) );
					$line_items[] = array(
						'accountId'	=> $this->settings['expense_discounts'],
						'amount'		=> $discount,
						'balance'	=> 'DEBIT',
					);
					$total_discounts += $discount;
				}

				/**
				 * Fees (credit)
				 */
				$fees = $this->getEDDItemFees( $item_id, $payment->cart_details );

				if ( ! empty( $fees ) ) { // array
/*
$fees[ $id ] = array(
	'amount'      => $order_fee->subtotal,
	'label'       => $order_fee->description,
	'no_tax'      => $no_tax,
	'type'        => 'fee',
	'price_id'    => $price_id,
	'download_id' => $download_id,
);
*/
					foreach ( $fees as $fee ) {
						$line_items[] = array(
							'accountId'	=> $this->settings['purchase_fees'],
							'amount'		=> number_format( $fee['amount'], 2, '.', '' ),
							'balance'	=> 'CREDIT',
						);
						$total_fees += $fee['amount']
					}

				}

				/**
				 * Income (credit)
				 */
				$subtotal = $this->getEDDItemSubtotal( $item_id, $payment->cart_details );
				if ( $subtotal ) {
					/**
					 * Add download product (credit) to line items
					 * The accountId will be EDD product:income account Wave ID
					 */
					$line_items[] = array(
						'accountId'	=> $this->getWaveAccount( $item_id, $price_id ),
						'amount'		=> $subtotal, // Gateway amount received (before gateway fees)
						'balance'	=> 'CREDIT'
					);
				}

			} // end foreach ( $payment->downloads as $download )


			/**
			 * Add merchant fees (debit) to line items
			 */
			if ( ! empty( $merchant_fee ) ) {

				$line_items[] = array(
					'accountId'	=> $this->settings['stripe_fees_account_id'],
					'amount'		=> $merchant_fee,
					'balance'	=> 'DEBIT'
				);
			}

			/*
				// EU VAT TAX (pass-thru tax)
				if ( in_array( $this->country, array( 'AT','BE','EE','FI','FR','SK','DE','TR','LU','CH','CZ','LV','NL','ES','SI','IT','IE','PT','PL','IS','GR','SE','DK','NO','HU' ) ) ) {
					$tax = edd_get_payment_tax( $payment_id );
					// error_log( 'Tax: ' . print_r( $tax, true ) );
					if ( isset( $tax ) ) {
						$line_items[] = array(
							'accountId' => $this->getTaxAccount( $this->country ),
							'amount'	=> $tax,
							'balance'	=> 'CREDIT'
						);
					}
				}
			*/

			// error_log( 'EDD + Wave: Stripe line items: ' . print_r( $line_items, true ) );

			if ( ! $balance_trans_retrieved && isset( $fee ) ) {

				if ( $net !== ( $amount + $total_fees - $total_discounts - $merchant_fee ) ) {

					error_log( 'EDD + Wave: the math doesn\'t add up! Amount: ' . print_r( $amount, true ) . ', Fee: ' . print_r( $fee, true ) . ', Discount: ' . print_r( $discount, true ) );
					return;

				}

			}

			$this->createWaveTransaction( $payment, $this->settings['stripe_anchor_account_id'], $line_items, $net );

		}

		/**
		 * Get full price of EDD item
		 *
		 * @param  string $item_id
		 * @param  array $cart_details
		 * @return float|boolean
		 */
		protected function getEDDItemFullPrice( $item_id, $cart_details ) {

			foreach ( $cart_details as $cart_detail ) {
				if ( $item_id === $cart_detail['id'] ) {
					return $cart_detail['item_price'];
				}
			}
			return false; // Yikes

		}

		/**
		 * Get discount off EDD item, if exists
		 *
		 * @param  string $item_id
		 * @param  array $cart_details
		 * @return float|boolean
		 */
		protected function getEDDItemDiscount( $item_id, $cart_details ) {

			foreach ( $cart_details as $cart_detail ) {
				if ( $item_id === $cart_detail['id'] ) {
					$discount = number_format( $cart_detail['discount'], 2, '.', '' );
					if ( '0.00' !== $discount ) {
						return $discount;
					}
				}
			}
			return false;

		}

		/**
		 * Get EDD item fees
		 *
		 * @param  string $item_id
		 * @param  array $cart_details
		 * @return array|false
		 */
		protected function getEDDItemFees( $item_id, $cart_details ) {

			foreach ( $cart_details as $cart_detail ) {
				if ( $item_id === $cart_detail['id'] ) {
					return $cart_detail['fees'];
				}
			}
			return false;

		}

		/**
		 * Get EDD item subtotal
		 *
		 * @param  string $item_id
		 * @param  array $cart_details
		 * @return float|boolean
		 */
		protected function getEDDItemSubtotal( $item_id, $cart_details ) {

			foreach ( $cart_details as $cart_detail ) {
				if ( $item_id === $cart_detail['id'] ) {
					return $cart_detail['subtotal'];
				}
			}
			return false; // Yikes

		}


		/**
		 * Create a Wave Transaction
		 *
		 * @param  object $payment
		 * @param  string $anchor_account_id
		 * @param  array  $line_items
		 * @param  float  $net
		 * @return void
		 */
		public function createWaveTransaction( $payment, $anchor_account_id, $line_items, $net ) {

			/**
			 *
			 * Gather data to send
			 *
			 */
			$data = wp_json_encode([

				'query'		=> 'mutation ($inputMoneyTransactionCreate: MoneyTransactionCreateInput!) { moneyTransactionCreate(input: $inputMoneyTransactionCreate) { didSucceed, inputErrors { code, message, path } } }',
				'variables'	=> [

					'inputMoneyTransactionCreate' => [

						'businessId'		=> $this->business_id,
						'externalId'		=> strval( $payment->ID ),
						'date'			=> date( 'Y-m-d' ),
						'description'	=> 'EDD order #' . $payment->ID . ' from ' . $payment->first_name . ' ' . $payment->last_name,
						'notes'			=> 'Email: ' . $payment->email,

						/**
						 * ANCHOR.
						 * The bank/credit card account from which the transaction takes place is the Anchor
						 * https://community.waveapps.com/discussion/6415/what-exactly-is-the-the-anchor-account-in-a-moneytransaction
						 */
						'anchor'			=> [
							'accountId'		=> $anchor_account_id,
							'amount'			=> $net,
							'direction'		=> 'DEPOSIT'
						],
						'lineItems'		=> $line_items

					] // end 'inputMoneyTransactionCreate'

				] // end 'variables'

			]); // end $data

			$createdPayment = $this->httpRequest( $data );

			if ( $createdPayment && true == $createdPayment['data']['moneyTransactionCreate']['didSucceed'] ) {
				return; // we are successful/finished
			}

			error_log( 'EDD + Wave: Failed sending to Wave. Payment response ' . print_r( $createdPayment, true ) );

		}

		/**
		 * Make HTTP request to Wave API
		 *
		 * @param  string $data HTTP request body
		 * @return string
		 */
		private function httpRequest( $data ) {

			$headers = array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type' => 'application/json',
			);

			$response = wp_remote_post( 'https://gql.waveapps.com/graphql/public', array(
					'timeout'	  => 10, // timeout set low because we are trying to not disrupt checkout flow
					'headers'	  => $headers,
					'body'		  => $data,
				)
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'EDD + Wave: HTTP request error' . print_r( $response->get_error_message(), true) );
				return false;
			}

			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				error_log( 'EDD + Wave: HTTP status code was not 200' );
				return false;
			}

			return json_decode( $response['body'], true );

		}

		/**
		 * Get Wave account from product name
		 *
		 * @param string $id Numeric
		 * @param string $price_id Numeric, for EDD variable pricing
		 * @return boolean|string Wave ID
		 */
		protected function getWaveAccount( $id, $price_id ) {

			if ( ! is_numeric( $id ) ) {
				return false;
			}

			if ( empty( $price_id ) ) {
				// Price ID is only going to be empty for EDD downloads without variation pricing
				return get_post_meta( $id, '_wave_income_account', true );
			} else {
				$_variable_income_account = get_post_meta( $id, '_wave_variable_income_account', true );
				if ( ! empty( $_variable_income_account ) ) {
					// $price_id will be the array key for the price variation
					return $_variable_income_account[$price_id];
				}
			}
			return false;

		}


		/**
		 * Get businesses from Wave Apps
		 *
		 * @return object
		 */
		public function getBusinesses() {

			$data = wp_json_encode([ 'query' => 'query { businesses { edges { node { id name } } } }' ]);

			return $this->httpRequest( $data );

		}

		/**
		 * Get accounts (by type) from Wave Apps
		 *
		 * @param string $types ASSET, EQUITY, EXPENSE, INCOME, LIABILITY
		 * 		Could also be JSON-formatted array, e.g. [ 'INCOME', 'EXPENSE' ]
		 * @return object|bool
		 */
		public function getAccounts( $type = "INCOME" ) {

			$data = wp_json_encode([ 'query' => 'query ($businessId: ID!, $page: Int!, $pageSize: Int!, $types: [AccountTypeValue!] ) {
					business(id: $businessId) {
							id
							accounts(page: $page, pageSize: $pageSize, types: $types) {
											pageInfo { currentPage totalPages totalCount }
											edges { node {
													id
													name
													description
													displayId
													type { name value }
													subtype { name value }
													normalBalanceType
													isArchived
											} } } } }',
									 'variables' => array(
										 'businessId'	=> $this->business_id,
										 'types'			=> $type,
										 'page'			=> 1,
										 'pageSize'		=> 50,

									 ) // end 'variables'

			]); // end $data

			return $this->httpRequest( $data );

		}

		/**
		 * Get Wave account from product name
		 *
		 * @param int $id
		 * @return string Wave ID
		 */
		private function getWaveAccount2( $id ) {

			$id = (int) $id;

			// id numbers can be seen in the Wave product edit screen after /edit in the URL
			$accounts = array(

				'QWNjb3VudDo4NDgwNTEwMDU0MjA0ODQ0ODk7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'			=> 19097, // WooCommerce Gift Wrapper Plus
				'QWNjb3VudDoxMDUwMTA4MjA4NTY5Mzk5MTQ3O0J1c2luZXNzOjA4ZWQ0YmUyLTMyNWQtNDI4NS1hMzFmLTNiNTkyNGQxMWQ2Ng=='		=> 22388, // WooStamper PDF
				'QWNjb3VudDoxMDUwMTI3NDY4NzI5NDgwNzEyO0J1c2luZXNzOjA4ZWQ0YmUyLTMyNWQtNDI4NS1hMzFmLTNiNTkyNGQxMWQ2Ng=='		=> 23115, // WP TCPDF Bridge
				'QWNjb3VudDoxMDk0MzQwOTgyMDIwNzgxOTA1O0J1c2luZXNzOjA4ZWQ0YmUyLTMyNWQtNDI4NS1hMzFmLTNiNTkyNGQxMWQ2Ng=='		=> 22500, // Download Monitor Stamper
				'QWNjb3VudDoxMDk0MzQwNjMwNjk3NDkwMTgwO0J1c2luZXNzOjA4ZWQ0YmUyLTMyNWQtNDI4NS1hMzFmLTNiNTkyNGQxMWQ2Ng=='		=> 22435, // EDDiStamper PDF

				'QWNjb3VudDo2MDIyMTk2ODQ0NTE4NTc1NjM7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'			=> 19104, // EDDiMark PDF
				'QWNjb3VudDo2MDIyMTk2ODgyNDM1MDg0NzE7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'			=> 19110, // WaterWoo PDF Premium

				// 'QWNjb3VudDo2MTQ2MTY0OTY3ODU2MDE1NDM7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'		=> 'Discounts',
				// 'QWNjb3VudDo2MDIyMTk2ODU3MzUzMTQ2MTc7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'		=> 'PayPal Sales',
				// 'QWNjb3VudDo2MDIyMTk2ODcxMTk0MzQ5NzE7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'		=> 'Stripe Sales',
				// 'QWNjb3VudDo2MDIyMTk2ODY3MDgzOTMxNjk7QnVzaW5lc3M6MDhlZDRiZTItMzI1ZC00Mjg1LWEzMWYtM2I1OTI0ZDExZDY2'		=> 'Sales',

			);

			foreach ( $accounts as $wave_id => $product_id ) {
				if ( $product_id == $id ) {
					return $wave_id;
				}

			}
			return false;

		}



		/**
		 * Get country name from country code
		 * Could also be done other ways: https://stackoverflow.com/questions/17842003/php-intl-country-code-2-chars-to-country-name
		 *
		 */
		public function getCountry( $code ) {

			$code = strtoupper( $code );

			$countryList = array(

				"US" => "United States",
				"GB" => "United Kingdom",
				"NL" => "Netherlands",
				"FR" => "France",
				"IT" => "Italy",
				"ES" => "Spain",
				"PL" => "Poland",
				"DE" => "Germany",
				"DK" => "Denmark",
				"BE" => "Belgium",
				"BR" => "Brazil",
				"MX" => "Mexico",
				"PT" => "Portugal",
				"CA" => "Canada",
				"NZ" => "New Zealand",
				"LU" => "Luxembourg",
				"SE" => "Sweden",
				"HU" => "Hungary",
				"NO" => "Norway",
				"AU" => "Australia",
				"AT" => "Austria",
				"CH" => "Switzerland",
				"RU" => "Russia",
				"BD" => "Bangladesh",
				"BF" => "Burkina Faso",
				"BG" => "Bulgaria",
				"BA" => "Bosnia and Herzegovina",
				"BB" => "Barbados",
				"WF" => "Wallis and Futuna",
				"BL" => "Saint Barthelemy",
				"BM" => "Bermuda",
				"BN" => "Brunei",
				"BO" => "Bolivia",
				"BH" => "Bahrain",
				"BI" => "Burundi",
				"BJ" => "Benin",
				"BT" => "Bhutan",
				"JM" => "Jamaica",
				"BV" => "Bouvet Island",
				"BW" => "Botswana",
				"WS" => "Samoa",
				"BQ" => "Bonaire, Saint Eustatius and Saba ",
				"BS" => "Bahamas",
				"JE" => "Jersey",
				"BY" => "Belarus",
				"BZ" => "Belize",
				"RW" => "Rwanda",
				"RS" => "Serbia",
				"TL" => "East Timor",
				"RE" => "Reunion",
				"TM" => "Turkmenistan",
				"TJ" => "Tajikistan",
				"RO" => "Romania",
				"TK" => "Tokelau",
				"GW" => "Guinea-Bissau",
				"GU" => "Guam",
				"GT" => "Guatemala",
				"GS" => "South Georgia and the South Sandwich Islands",
				"GR" => "Greece",
				"GQ" => "Equatorial Guinea",
				"GP" => "Guadeloupe",
				"JP" => "Japan",
				"GY" => "Guyana",
				"GG" => "Guernsey",
				"GF" => "French Guiana",
				"GE" => "Georgia",
				"GD" => "Grenada",
				"GA" => "Gabon",
				"SV" => "El Salvador",
				"GN" => "Guinea",
				"GM" => "Gambia",
				"GL" => "Greenland",
				"GI" => "Gibraltar",
				"GH" => "Ghana",
				"OM" => "Oman",
				"TN" => "Tunisia",
				"JO" => "Jordan",
				"HR" => "Croatia",
				"HT" => "Haiti",
				"HK" => "Hong Kong",
				"HN" => "Honduras",
				"HM" => "Heard Island and McDonald Islands",
				"VE" => "Venezuela",
				"PR" => "Puerto Rico",
				"PS" => "Palestinian Territory",
				"PW" => "Palau",
				"SJ" => "Svalbard and Jan Mayen",
				"PY" => "Paraguay",
				"IQ" => "Iraq",
				"PA" => "Panama",
				"PF" => "French Polynesia",
				"PG" => "Papua New Guinea",
				"PE" => "Peru",
				"PK" => "Pakistan",
				"PH" => "Philippines",
				"PN" => "Pitcairn",
				"PM" => "Saint Pierre and Miquelon",
				"ZM" => "Zambia",
				"EH" => "Western Sahara",
				"EE" => "Estonia",
				"EG" => "Egypt",
				"ZA" => "South Africa",
				"EC" => "Ecuador",
				"VN" => "Vietnam",
				"SB" => "Solomon Islands",
				"ET" => "Ethiopia",
				"SO" => "Somalia",
				"ZW" => "Zimbabwe",
				"SA" => "Saudi Arabia",
				"ER" => "Eritrea",
				"ME" => "Montenegro",
				"MD" => "Moldova",
				"MG" => "Madagascar",
				"MF" => "Saint Martin",
				"MA" => "Morocco",
				"MC" => "Monaco",
				"UZ" => "Uzbekistan",
				"MM" => "Myanmar",
				"ML" => "Mali",
				"MO" => "Macao",
				"MN" => "Mongolia",
				"MH" => "Marshall Islands",
				"MK" => "Macedonia",
				"MU" => "Mauritius",
				"MT" => "Malta",
				"MW" => "Malawi",
				"MV" => "Maldives",
				"MQ" => "Martinique",
				"MP" => "Northern Mariana Islands",
				"MS" => "Montserrat",
				"MR" => "Mauritania",
				"IM" => "Isle of Man",
				"UG" => "Uganda",
				"TZ" => "Tanzania",
				"MY" => "Malaysia",
				"IL" => "Israel",
				"IO" => "British Indian Ocean Territory",
				"SH" => "Saint Helena",
				"FI" => "Finland",
				"FJ" => "Fiji",
				"FK" => "Falkland Islands",
				"FM" => "Micronesia",
				"FO" => "Faroe Islands",
				"NI" => "Nicaragua",
				"NA" => "Namibia",
				"VU" => "Vanuatu",
				"NC" => "New Caledonia",
				"NE" => "Niger",
				"NF" => "Norfolk Island",
				"NG" => "Nigeria",
				"NP" => "Nepal",
				"NR" => "Nauru",
				"NU" => "Niue",
				"CK" => "Cook Islands",
				"XK" => "Kosovo",
				"CI" => "Ivory Coast",
				"CO" => "Colombia",
				"CN" => "China",
				"CM" => "Cameroon",
				"CL" => "Chile",
				"CC" => "Cocos Islands",
				"CG" => "Republic of the Congo",
				"CF" => "Central African Republic",
				"CD" => "Democratic Republic of the Congo",
				"CZ" => "Czech Republic",
				"CY" => "Cyprus",
				"CX" => "Christmas Island",
				"CR" => "Costa Rica",
				"CW" => "Curacao",
				"CV" => "Cape Verde",
				"CU" => "Cuba",
				"SZ" => "Swaziland",
				"SY" => "Syria",
				"SX" => "Sint Maarten",
				"KG" => "Kyrgyzstan",
				"KE" => "Kenya",
				"SS" => "South Sudan",
				"SR" => "Suriname",
				"KI" => "Kiribati",
				"KH" => "Cambodia",
				"KN" => "Saint Kitts and Nevis",
				"KM" => "Comoros",
				"ST" => "Sao Tome and Principe",
				"SK" => "Slovakia",
				"KR" => "South Korea",
				"SI" => "Slovenia",
				"KP" => "North Korea",
				"KW" => "Kuwait",
				"SN" => "Senegal",
				"SM" => "San Marino",
				"SL" => "Sierra Leone",
				"SC" => "Seychelles",
				"KZ" => "Kazakhstan",
				"KY" => "Cayman Islands",
				"SG" => "Singapore",
				"SD" => "Sudan",
				"DO" => "Dominican Republic",
				"DM" => "Dominica",
				"DJ" => "Djibouti",
				"VG" => "British Virgin Islands",
				"YE" => "Yemen",
				"DZ" => "Algeria",
				"UY" => "Uruguay",
				"YT" => "Mayotte",
				"UM" => "United States Minor Outlying Islands",
				"LB" => "Lebanon",
				"LC" => "Saint Lucia",
				"LA" => "Laos",
				"TV" => "Tuvalu",
				"TW" => "Taiwan",
				"TT" => "Trinidad and Tobago",
				"TR" => "Turkey",
				"LK" => "Sri Lanka",
				"LI" => "Liechtenstein",
				"LV" => "Latvia",
				"TO" => "Tonga",
				"LT" => "Lithuania",
				"LR" => "Liberia",
				"LS" => "Lesotho",
				"TH" => "Thailand",
				"TF" => "French Southern Territories",
				"TG" => "Togo",
				"TD" => "Chad",
				"TC" => "Turks and Caicos Islands",
				"LY" => "Libya",
				"VA" => "Vatican",
				"VC" => "Saint Vincent and the Grenadines",
				"AE" => "United Arab Emirates",
				"AD" => "Andorra",
				"AG" => "Antigua and Barbuda",
				"AF" => "Afghanistan",
				"AI" => "Anguilla",
				"VI" => "U.S. Virgin Islands",
				"IS" => "Iceland",
				"IR" => "Iran",
				"AM" => "Armenia",
				"AL" => "Albania",
				"AO" => "Angola",
				"AQ" => "Antarctica",
				"AS" => "American Samoa",
				"AR" => "Argentina",
				"AW" => "Aruba",
				"IN" => "India",
				"AX" => "Aland Islands",
				"AZ" => "Azerbaijan",
				"IE" => "Ireland",
				"ID" => "Indonesia",
				"UA" => "Ukraine",
				"QA" => "Qatar",
				"MZ" => "Mozambique"
			);

			if( ! $countryList[$code] ) {
				return $code;
			} else {
				return $countryList[$code];
			}

		}



	} // end class Sagehen_EDD_Wave

endif;

function EDDWave() {
	return Sagehen_EDD_Wave::instance();
}
EDDWave();