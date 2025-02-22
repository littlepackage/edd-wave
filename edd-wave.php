<?php
/**
 * Plugin Name: Easy Digital Downloads Wave
 * Description: This amazing plugin moves a successful EDD purchase into Wave Apps accounting, with a payment entry under Accounting -> Transactions.
 *
 * Version: 1.14
 * Author: Little Package
 * Text Domain: edd-wave
 *
 * Easy Digital Downloads Wave
 * Copyright: (c) 2022-2023 Little Package
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

use EDD\Gateways\PayPal;
use EDD\Vendor\Stripe\Stripe as Stripe;
use EDD\Vendor\Stripe\BalanceTransaction as BalanceTransaction;


defined( 'ABSPATH' ) || exit; // Exit if accessed directly

if ( ! class_exists( 'Sagehen_EDD_Wave' ) ) :

	class Sagehen_EDD_Wave {

		private $settings;

		private $business_id;

		private $payment_completed_date;

		private $full_access_token;

		/**
		 * Single instance of the Sagehen_EDD_Wave class
		 *
		 * @var Sagehen_EDD_Wave
		 */
		protected static $_instance = null;

		/**
		 * Instantiator
		 *
		 * @return Sagehen_EDD_Wave
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

			$this->business_id = $this->settings['business_id'] ?? '';

			$this->payment_completed_date = date( 'Y-m-d' );

			$this->full_access_token = $this->settings['full_access_token'] ?? '';

			add_action( 'init', [ $this, 'init' ] );

			// Catch PayPal payment details via the EDD Gateway API
			add_action( 'edd_after_payment_actions', [ $this, 'edd_after_payment_actions' ], 10, 3 );

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
				define( 'EDD_WAVE_VERSION', '1.14' );
			}

		}

		/**
		 * Include required files
		 *
		 * @return void
		 */
		private function includes() {

			require_once __DIR__ . '/includes/class-edd-wave-settings.php';

		}

		/**
		 * Fire up the admin settings class
		 *
		 * @return void
		 */
		public function init() {

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
		 * @return void
		 */
		public function edd_after_payment_actions( $payment_id, $payment, $customer ) {

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

				error_log( 'EDD + Wave: PayPal\API exception message: ' . print_r( $e->getMessage(), true ));
				return;

			}

			if ( empty( $response->id ) || 'completed' !== strtolower( $response->status ) ) {
				error_log( 'EDD + Wave: $response->id not found or $response->status not "COMPLETED"' );
				return;
			}

			if ( ! $payment->downloads ) {
				error_log( 'EDD + Wave: EDD payment strangely doesn\'t seem to include any downloads.' );
				return;
			}

			$line_items = $this->initiateLineItems( $payment );

			if ( ! $response->purchase_units ) {
				error_log( 'EDD + Wave: Bad (unusable) response from PayPal API.' );
				return;
			}

			// error_log( 'PayPal response: ' . print_r( $response, true ) );

			if ( isset( $response->create_time ) ) {
				$this->payment_completed_date = date( 'Y-m-d', strtotime( $response->create_time ) );
			}

			/**
			 * Cart (download) details
			 * get array of lineItems for Wave API inputMoneyTransactionCreate
			 *
			 */
			foreach( $response->purchase_units as $purchase_unit ) {

				// error_log( 'EDD + Wave: PayPal purchase unit: ' . print_r( $purchase_unit, true ) );

				$net = floatval( $purchase_unit->payments->captures[0]->seller_receivable_breakdown->net_amount->value );

				$merchant_fee = $purchase_unit->payments->captures[0]->seller_receivable_breakdown->paypal_fee->value ?? null;

				if ( $merchant_fee ) {

					/**
					 * Add merchant fees (debit) to line items
					 */
					$line_items[] = [
						'accountId' => $this->settings['paypal_fees_account_id'],
						'amount'    => $merchant_fee,
						'balance'   => 'DEBIT'
					];

				}

			}

			if ( empty( $line_items ) ) {
				error_log( 'Wave + EDD: Error - no data collected to send to Wave!' );
				return;
			}

			// error_log( 'EDD + Wave: PayPal line items: ' . print_r( $line_items, true ) );

			// PayPal method ID passed:
			$this->createWaveTransaction( $payment, $this->settings['paypal_anchor_account_id'], $line_items, $net );

		}

		/**
		 * Gather data after Stripe Payment complete
		 *
		 * @param object $payment
		 * @param int $intent_id
		 * @return void
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

			if ( ! $payment->downloads ) {
				error_log( 'EDD + Wave: EDD payment strangely doesn\'t seem to include any downloads.' );
				return;
			}

// error_log( 'EDD + Wave: $payment object: ' . print_r( $payment, true ) );

			$user_info = edd_get_payment_meta_user_info( $payment->ID );
			$billing_address = ! empty( $user_info['address'] ) ? $user_info['address'] : [ 'line1' => '', 'line2' => '', 'city' => '', 'country' => '', 'state' => '', 'zip' => '' ];
			$country = $billing_address['country'];
			$international = ! ( $country == 'US' );


			// GET THE STRIPE INTENT FROM THE INTENT ID
			$payment_intent = edds_api_request( 'PaymentIntent', 'retrieve', $intent_id );

// error_log( 'EDD + Wave: Payment intent: ' . print_r( $payment_intent, true ) );

			/**
			 * If payment wasn't successful, stop
			 */
			if ( 'succeeded' !== $payment_intent->status ) {
				error_log( 'EDD + Wave: Payment intent status not a success, aborting sending data to Wave.' );
				return;
			}

			$billing_country_code = '';
			$billing_country = '';
			$charge = [];
			// Get the Stripe Charge object
			if ( isset( $payment_intent->latest_charge ) ) {

				$charge = edds_api_request( 'Charge', 'retrieve', $payment_intent->latest_charge );

				$billing_country_code = $charge->billing_details->address->country;

				// GET FORMATTED BILLING COUNTRY NAME
				$billing_country = $this->getCountry( $billing_country_code );
			}
			if ( empty( $charge ) ) {
				$charge = edds_api_request( 'charge', 'retrieve', $payment->ID );
			}

			$balance_trans_retrieved = false;

			// Try to get Balance Transaction from Stripe
			try {

				$balance_transaction = edds_api_request( 'BalanceTransaction', 'retrieve', $charge->balance_transaction );

				// error_log( 'EDD + Wave Balance Transaction: ' . print_r( $balance_transaction, true ) );

				$amount = floatval( $balance_transaction->amount / 100 );
				$merchant_fee = floatval( $balance_transaction->fee / 100 );
				$net = floatval( $balance_transaction->net / 100 );

				$balance_trans_retrieved = true;

			// Then if no luck, we try another method (using intent)
			} catch( Exception $e ) { // if no, use Intent

				error_log( 'Balance Transaction fetch failed. Using $payment_intent->amount_received instead, which might fail due to merchant fee variability. More info: ' . $e->getMessage() );

				$amount = floatval( $payment_intent->amount_received / 100 );
				if ( 'US' === strtoupper( $billing_country_code ) ) {
					$merchant_fee = floatval( ( $amount * .029 ) + 0.30 );
				} else {
					$merchant_fee = floatval( ( $amount * .039 ) + 0.30 );
				} // there is also an additional 1% Stripe charge for currency conversions but those will probably be less common

				$net = '';

			}

			$line_items = $this->initiateLineItems( $payment );

			$this->payment_completed_date = date( 'Y-m-d', $payment_intent->created );

			/**
			 * Add merchant fees (debit) to line items
			 */
			if ( ! empty( $merchant_fee ) ) {

				$line_items[] = [
					'accountId' => $this->settings['stripe_fees_account_id'],
					'amount'    => $merchant_fee,
					'balance'   => 'DEBIT'
				];
			}

			if ( empty( $line_items ) ) {
				error_log( 'Wave + EDD: Error - no data collected to send to Wave!' );
				return;
			}

			// error_log( 'EDD + Wave: Stripe line items: ' . print_r( $line_items, true ) );

			$this->createWaveTransaction( $payment, $this->settings['stripe_anchor_account_id'], $line_items, $net );

		}

		/**
		 * Start a lineItem array for the Wave Apps moneyTransactionCreate API mutation
		 *
		 * @param object $payment
		 *
		 * @return array
		 */
		private function initiateLineItems( $payment ) {

			
			// Looks like this (for download with variation pricing)
			/*
			[0] => [
				[0] => [
					[id] => 2235
					[quantity] => 1
					[options] => Array (
						[quantity] => 1
						[price_id] => 1
					]
				)
				[1] => [
					[id] => 58392
					[quantity] => 1
					[options] => Array (
						[quantity] => 1
						[price_id] => 3
					]
				]
			)
			*/

			if ( isset( $payment->completed_date ) ) {
				$this->payment_completed_date = date( 'Y-m-d', strtotime( $payment->completed_date ) );
			}

			/**
			 * Get an array of line items "lineItems" for Wave API moneyTransactionCreate
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
				 * Income (credit)
				 */
				$subtotal = $this->getEDDItemSubtotal( $item_id, $payment->cart_details );
				if ( $subtotal ) {
					/**
					 * Add download product (credit) to line items
					 * The accountId will be EDD product:income account Wave ID
					 */
					$line_items[] = [
						'accountId' => $this->getWaveAccount( $item_id, $price_id ),
						'amount'    => $subtotal, // Gateway amount received (before gateway fees)
						'balance'   => 'CREDIT'
					];
				}

				/**
				 * Discounts (debit)
				 */
				$discount = $this->getEDDItemDiscount( $item_id, $payment->cart_details );

				if ( $discount ) {
					// error_log( 'Discount: ' . print_r( $discount, true ) );
					$line_items[] = [
						'accountId' => $this->settings['expense_discounts'],
						'amount'    => $discount,
						'balance'   => 'DEBIT',
					];
				}

				/**
				 * Fees (credit)
				 */
				$fees = $this->getEDDItemFees( $item_id, $payment->cart_details );

				if ( ! empty( $fees ) ) { // array
					foreach ( $fees as $fee ) {
						$line_items[] = [
							'accountId' => $this->settings['purchase_fees'],
							'amount'    => number_format( $fee['amount'], 2, '.', '' ),
							'balance'   => 'CREDIT',
						];
					}

				}

			} // end foreach ( $payment->downloads as $download )

			return $line_items;

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

				'query'     => 'mutation ($inputMoneyTransactionCreate: MoneyTransactionCreateInput!) { moneyTransactionCreate(input: $inputMoneyTransactionCreate) { didSucceed, inputErrors { code, message, path } } }',
				'variables' => [

					'inputMoneyTransactionCreate' => [

						'businessId'  => $this->business_id,
						'externalId'  => strval( $payment->ID ),
						'date'        => $this->payment_completed_date,
						'description' => 'EDD #' . $payment->ID . ' from ' . $payment->first_name . ' ' . $payment->last_name,
						'notes'       => 'Email: ' . $payment->email,

						/**
						 * ANCHOR.
						 * The bank/credit card account from which the transaction takes place is the Anchor
						 * The "anchor" is an asset (cash and bank) or liability (credit card / LoC) : in our case, the merchant account
						 * https://web.archive.org/web/20200811134512/https://community.waveapps.com/discussion/6415/what-exactly-is-the-the-anchor-account-in-a-moneytransaction
						 */
						'anchor' => [
							'accountId' => $anchor_account_id,
							'amount'    => $net,
							'direction' => 'DEPOSIT'
						],
						'lineItems' => $line_items

					] // end 'inputMoneyTransactionCreate'

				] // end 'variables'

			]); // end $data

			$createdPayment = $this->httpRequest( $data );

			if ( $createdPayment && true == $createdPayment['data']['moneyTransactionCreate']['didSucceed'] ) {
				return; // we are successful/finished, no need to log error
			}

			error_log( 'EDD + Wave: Payment not created. More info: ' . print_r( $createdPayment, true ) );

		}

		/**
		 * Make HTTP request to Wave API
		 *
		 * @param  string $data HTTP request body
		 * @return array|boolean
		 */
		private function httpRequest( $data ) {

			$headers = [
				'Authorization' => 'Bearer ' . $this->full_access_token,
				'Content-Type' => 'application/json',
			];

			$response = wp_remote_post( 'https://gql.waveapps.com/graphql/public', [
					'timeout'   => 20, // timeout set low because we are trying to not disrupt checkout flow
					'headers'   => $headers,
					'body'      => $data,
				]
			);

			if ( is_wp_error( $response ) ) {
				error_log( 'EDD + Wave: HTTP request error' . print_r( $response->get_error_message(), true) );
				return false;
			}

			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				return json_decode( $response['body'], true );
			}

			error_log( 'EDD + Wave: HTTP status code was not 200. It was ' . wp_remote_retrieve_response_code( $response ) );
			error_log( 'EDD + Wave: Response was ' . print_r( $response, true ) );
			error_log( 'full access code: ' . print_r( $this->full_access_token, true ) );
			return false;

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
		 * @return array
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
													type { name value }
													subtype { name value }
													isArchived
											} } } } }',
									 'variables' => [
										 'businessId'	=> $this->business_id,
										 'types'			=> $type,
										 'page'			=> 1,
										 'pageSize'		=> 100,
									 ] // end 'variables'

			]); // end $data

			return $this->httpRequest( $data );

		}


		/**
		 * Get country name from country code
		 * Could also be done other ways: https://stackoverflow.com/questions/17842003/php-intl-country-code-2-chars-to-country-name
		 *
		 */
		public function getCountry( $code ) {

			$code = strtoupper( $code );

			$countryList = [

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
			];

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

function edd_wave_edd_required_notice() {
	echo '<div class="error"><p>' . __( 'The EDD Wave plugin requires Easy Digital Downloads be installed and activated.', 'edd-wave' ) . '</p></div>';
}

function edd_wave_plugins_loaded() {

	if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
		add_action( 'admin_notices', 'edd_wave_edd_required_notice' );
		return;
	}

	EDDWave();

}
add_action( 'plugins_loaded', 'edd_wave_plugins_loaded', 9 );