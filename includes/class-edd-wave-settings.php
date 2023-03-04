<?php

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

class EDD_Wave_Settings {

	private $settings = [];

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->settings = (array) get_option( 'edd_wave', [] );

		// Add link to EDD Wave settings from WP plugins page
		add_filter( 'plugin_action_links_edd-wave/edd-wave.php', [ $this, 'add_settings_link' ] );

		// Add link to EDD Wave settings from the admin menu
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );

		// Enqueue JavaScript that runs the admin settings mapped tables
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ], 10 );

		// Save settings. Admin-side post including action value "edd_wave_settings" fires this hook
		add_action( 'admin_post_edd_wave_settings', [ $this, 'save_settings' ] );

	}

	/**
	 * Enqueue admin-end scripts
	 *
	 * @param string $hook
	 */
	public function admin_enqueue_scripts( $hook ) {

		if ( 'toplevel_page_edd-wave' !== $hook ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_script( 'edd-wave-admin-js', plugins_url( 'assets/js/admin' . $suffix . '.js', EDD_WAVE_PLUGIN_FILE ), ['jquery'], EDD_WAVE_VERSION, true );


	}

	/**
	 * Add settings link to WP plugin listing
	 *
	 * @param array $links
	 * @return array
	 */
	public function add_settings_link( $links ) {

		$action_links = array(
			'settings' => '<a href="' . admin_url( 'admin.php?page=edd-wave' ) . '" aria-label="' . esc_attr__( 'View EDD Wave settings', 'edd-wave' ) . '">' . esc_html__( 'Settings', 'edd-wave' ) . '</a>',
		);
		return array_merge( $action_links, $links );

	}

	/**
	 * Add settings to the WP Admin menu (under Settings)
	 *
	 * @return void
	 */
	public function admin_menu() {

		// Being cute but also stealing the Wave Apps icon
		$wiz_icon = 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPCEtLSBHZW5lcmF0b3I6IEFkb2JlIElsbHVzdHJhdG9yIDI3LjIuMCwgU1ZHIEV4cG9ydCBQbHVnLUluIC4gU1ZHIFZlcnNpb246IDYuMDAgQnVpbGQgMCkgIC0tPgo8c3ZnIHZlcnNpb249IjEuMSIgaWQ9IkxheWVyXzEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IgoJIHZpZXdCb3g9IjAgMCA0MiA0MiIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgNDIgNDI7IiB4bWw6c3BhY2U9InByZXNlcnZlIj4KPHN0eWxlIHR5cGU9InRleHQvY3NzIj4KCS5zdDB7ZmlsbC1ydWxlOmV2ZW5vZGQ7Y2xpcC1ydWxlOmV2ZW5vZGQ7ZmlsbDojMDg0Rjk5O30KCS5zdDF7ZmlsbC1ydWxlOmV2ZW5vZGQ7Y2xpcC1ydWxlOmV2ZW5vZGQ7ZmlsbDojNUVCN0ZGO30KCS5zdDJ7ZmlsbC1ydWxlOmV2ZW5vZGQ7Y2xpcC1ydWxlOmV2ZW5vZGQ7ZmlsbDojMTQ3OUZCO30KPC9zdHlsZT4KPHBhdGggY2xhc3M9InN0MCIgZD0iTTQsMzFjMi40LDAuOCw0LjktMC41LDUuNy0yLjdsMi43LTguN2MwLjctMi4yLTAuNi00LjctMy01LjVMOS4yLDE0Yy0yLjQtMC44LTQuOSwwLjUtNS43LDIuN2wtMi43LDguNwoJYy0wLjcsMi4yLDAuNiw0LjcsMyw1LjVMNCwzMXoiLz4KPHBhdGggY2xhc3M9InN0MSIgZD0iTTMyLjUsNi4xQzMzLDQuOSwzNCwzLjksMzUuMSwzLjRjMC4xLDAsMC4xLTAuMSwwLjItMC4xYzAsMCwwLjEsMCwwLjEsMGMwLjktMC40LDEuOS0wLjQsMi45LTAuMWwwLjIsMC4xCgljMi40LDAuOCwzLjcsMy41LDIuOCw2YzAsMC02LjUsMjIuMS02LjUsMjIuMWMtMS43LDUuMS03LjEsNy43LTExLjgsNi40YzAsMC0xLjMtMC40LTEuOC0wLjdDMjUuOCwzNi4xLDMxLjYsOC4zLDMyLjUsNi4xeiIvPgo8cGF0aCBjbGFzcz0ic3QyIiBkPSJNNC44LDMzLjljMSwwLjMsMiwwLjMsMywwLjFjMC4yLDAsMC4zLTAuMSwwLjUtMC4xYzEuOS0wLjYsMy42LTIuMiw0LjMtNC4zbDUuMS0xNi44YzAuOC0yLjYsMy41LTQsNS44LTMuMwoJbDAuMiwwLjFjMi40LDAuOCwzLjcsMy41LDIuOCw2YzAsMC00LjgsMTUuOS01LjUsMTcuM2MtNC45LDktMTQuMiw0LjYtMTYuOSwwLjhjMC4yLDAuMSwwLjMsMC4xLDAuNSwwLjJMNC44LDMzLjl6Ii8+Cjwvc3ZnPgo=';

		add_menu_page( 'EDD Wave', 'EDD Wave', 'edit_posts', 'edd-wave', array( $this, 'options_page' ), $wiz_icon, '151' );

	}

	/**
	 * Output a Wordpress settings page
	 *
	 * @return void
	 */
	public function options_page() { ?>

		<div class="wrap">
			<h1>EDD Wave Settings</h1>

			<form id="edd-wave-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<input type="hidden" class="regular-text ltr" name="edd_wave_settings_nonce" value="<?php echo wp_create_nonce( 'edd-wave-settings' ); ?>"/>
				<input type="hidden" name="action" value="edd_wave_settings">

				<?php $api_key = $this->settings['api_key'] ?? ''; ?>
				<p>
					<label for="business_id">Wave API Key</label><br />
					<input id="api_key" type="text" class="regular-text ltr" name="api_key" value="<?php esc_attr_e( $api_key ) ?? ''; ?>">
				</p>

				<?php // Get list of businesses from Wave Apps API
				$businesses = EDDWave()->getBusinesses(); ?>

				
				<p>
					<label for="business_id">Business ID</label><br />

					<select name="business_id">
						<?php
						$business_id = $this->settings['business_id'] ?? '';
						$option_html_output = '<option value="">&mdash; Select &mdash;</option>';

						if ( empty( $businesses ) ) {
							$option_html_output .= '</select><p style="color:red">No businesses were found on your Wave Apps account. You must set up a business and API access for this plugin to function.</p>';
						} else {
							foreach ( $businesses['data']['businesses']['edges'] as $edge ) {
								if ( $edge['node']['isArchived'] ) {
									continue;
								}
								$option_html_output .= '<option value="' . $edge['node']['id'] . '"' . selected( $edge['node']['id'], $business_id ) . '>' . $edge['node']['name'] . '</option>';
							}
						}
						echo $option_html_output;
						?>
					</select>
				</p>

				<?php if ( empty( $api_key ) && empty( $business_id ) ) { ?>	
					<p>The two previous fields must be filled and saved to continue.</p>
					<?php return;
				}

				$wave_asset_accounts = EDDWave()->getAccounts( 'ASSET' );

				if ( $wave_asset_accounts ) {
					if ( is_plugin_active( 'edd-paypal-commerce-pro/edd-paypal-commerce-pro.php' ) ) {
						$paypal_anchor_account_id = $this->settings['paypal_anchor_account_id'] ?? ''; ?>
						<h2>PayPal Account</h2>
						<p>
							<label for="paypal_anchor_account_id">PayPal Anchor Account ID</label><br />

							<select name="paypal_anchor_account_id">
								<?php
								$option_html_output = '<option value="">&mdash; Select &mdash;</option>';
								foreach ( $wave_asset_accounts['data']['business']['accounts']['edges'] as $edge ) {
									if ( $edge['node']['isArchived'] ) {
										continue;
									}
									$option_html_output .= '<option value="' . $edge['node']['id'] . '"' . selected( $edge['node']['id'], $paypal_anchor_account_id ) . '>' . $edge['node']['name'] . '</option>';
								}
								echo $option_html_output;
								?>
							</select>
						</p>

					<?php } ?>

					<?php if ( is_plugin_active( 'edd-stripe/edd-stripe.php' ) ) {
					$stripe_anchor_account_id = $this->settings['stripe_anchor_account_id'] ?? '';
					?>
					<h2>Stripe Account</h2>
					<p>
						<label for="stripe_anchor_account_id">Stripe Anchor Account ID</label><br />

						<select name="stripe_anchor_account_id">
							<?php $option_html_output = '<option value="">&mdash; Select &mdash;</option>';
							foreach ( $wave_asset_accounts['data']['business']['accounts']['edges'] as $edge ) {
								$option_html_output .= '<option value="' . $edge['node']['id'] . '"' . selected( $edge['node']['id'], $stripe_anchor_account_id ) . '>' . $edge['node']['name'] . '</option>';
							}
							echo $option_html_output;
							?>
						</select>

					<?php } ?>
				<?php } ?>


					<?php $this->output_income_settings_table(); ?>
					<?php $this->output_expenses_settings_table(); ?>

					<?php submit_button( null, '', 'edd-wave-submit' ); ?>
			</form>
		</div>

	<?php }

	/**
	 * Display income mapped settings table
	 * 
	 * @return void
	 */
	protected function output_income_settings_table() { ?>


		<h2>EDD -> Wave Product Mapping</h2>
		<p>Sorry these tables aren't pretty, but they get the job done. Match your products to your Chart of Account income items below.</p>
		<table>

			<thead>
			<tr>
				<th>Local EDD Product</th>
				<th>&nbsp;</th>
				<th>Matched Wave Income Account</th>
			</tr>
			</thead>
			<tbody>

			<?php

			// Get Wave INCOME accounts
			$wave_accounts = EDDWave()->getAccounts( 'INCOME' );

			// Create HTML <option>s containing Wave business income account ID -> names
			$option_html_output = '<option value="">&mdash; Select &mdash;</option>';
			foreach ( $wave_accounts['data']['business']['accounts']['edges'] as $edge ) {
				$option_html_output .= '<option value="' . $edge['node']['id'] . '">' . $edge['node']['name'] . '</option>';
			}

			// Now let's get all the EDD products
			$args = array(
				'post_type'     	=> 'download',
				'post_status'   	=> 'publish',
				'order'       	=> 'ASC',
				'orderby'       	=> 'title',
				'numberposts'  	=> -1,
				'no_found_rows'	=> true, // don't include pagination (faster)
			);
			$edd_downloads = get_posts( $args );

			foreach ( $edd_downloads as $index => $download ) {

				$_post_meta_income_account = get_post_meta( $download->ID, '_wave_income_account', true ) ?? '';

				echo '<tr id="edd-wave-row-' . $index . '">';
				echo '<td class="edd-wave-local-value">' . $download->post_title;
				echo '<input type="hidden" name="edd_wave_income[' . $download->ID . '][' . "parent" . ']" value="' . $_post_meta_income_account . '">';
				echo '</td>';
				echo '<td><span class="dashicons dashicons-arrow-right-alt"></span></td>';
				echo '<td class="edd-wave-remote-value"><select name="edd_wave_income_select-' . $index . '" class="edd-wave-select" data-selected="' . $_post_meta_income_account . '">' . $option_html_output . '</select></td>';
				echo '</tr>';

				// Add more rows if EDD product also has variable pricing
				if ( edd_has_variable_prices( $download->ID ) ) {

					$_post_meta_variable_income_account = get_post_meta( $download->ID, '_wave_variable_income_account', true ) ?? '';

// error_log( print_r( $_post_meta_variable_income_account['$key'], true ) );

					$prices = edd_get_variable_prices( $download->ID );
					foreach ( $prices as $key => $price ) {

						$_key = $_post_meta_variable_income_account[$key] ?? '';

						echo '<tr id="edd-wave-row-' . $index . '-' .$price["index"] . '">';
						echo '<td class="edd-wave-local-value">' . $download->post_title . ' - ' . $price["name"];
						echo '<input type="hidden" name="edd_wave_income[' . $download->ID . '][' . $key . ']" value="' . $_key . '">';
						echo '</td>';
						echo '<td><span class="dashicons dashicons-arrow-right-alt"></span></td>';
						echo '<td class="edd-wave-remote-value"><select name="edd_wave_income_select-' . $key . '" class="edd-wave-select" data-selected="' . $_key . '">' . $option_html_output . '</select></td>';
						echo '</tr>';

					}
				}
			}
			?>
			</tbody>

		</table>

		<?php
	}

	/**
	 * Display expenses mapped settings table
	 *
	 * @return void
	 */
	protected function output_expenses_settings_table() { ?>

		<h2>EDD -> Wave Expense Mapping</h2>
		<p>Match your fees, etc. to your Chart of Account expense items below.</p>
		<table>
			<thead>
			<tr>
				<th>Local Expenses</th>
				<th>&nbsp;</th>
				<th>Matched Wave Expense Account</th>
			</tr>
			</thead>
			<tbody>

			<?php

			// Get Wave EXPENSE accounts
			$wave_accounts = EDDWave()->getAccounts( 'EXPENSE' );

			// Create HTML <option>s containing Wave business expense account ID -> names
			$option_html_output = '<option value="">&mdash; Select &mdash;</option>';
			foreach ( $wave_accounts['data']['business']['accounts']['edges'] as $edge ) {
				if ( $edge['node']['isArchived'] ) {
					continue;
				}
				$option_html_output .= '<option value="' . $edge['node']['id'] . '">' . $edge['node']['name'] . '</option>';
			}
			$expense_discounts = $this->settings['expense_discounts'] ?? ''
			?>

			<tr id="edd-wave-row">
				<td class="edd-wave-local-value">Discounts<input type="hidden" name="expense_discounts" value="<?php esc_attr_e( $expense_discounts ); ?>"></td>
				<td><span class="dashicons dashicons-arrow-right-alt"></span></td>
				<?php
				echo '<td class="edd-wave-remote-value">';
				echo '<select name="expense_discounts_select" class="edd-wave-select" data-selected="' . $expense_discounts . '">';
				echo $option_html_output;
				echo '</select>';
				echo '</td>';
				?>
			</tr>

			<?php if ( is_plugin_active( 'edd-stripe/edd-stripe.php' ) ) {
				$stripe_fees_account_id = $this->settings['stripe_fees_account_id'] ?? '';
				?>

				<tr id="edd-wave-row">
					<td class="edd-wave-local-value">Stripe Merchant Account Fees<input type="hidden" name="stripe_fees_account_id" value="<?php esc_attr_e( $stripe_fees_account_id ); ?>"></td>
					<td><span class="dashicons dashicons-arrow-right-alt"></span></td>
					<?php
					echo '<td class="edd-wave-remote-value">';
					echo '<select name="stripe_fees_select" class="edd-wave-select" data-selected="' . $stripe_fees_account_id . '">';
					echo $option_html_output;
					echo '</select>';
					echo '</td>';
					?>
				</tr>

			<?php } ?>


			<?php if ( is_plugin_active( 'edd-paypal-commerce-pro/edd-paypal-commerce-pro.php' ) ) {
				$paypal_fees_account_id = $this->settings['paypal_fees_account_id'] ?? '';
				?>

				<tr id="edd-wave-row">
					<td class="edd-wave-local-value">PayPal Merchant Account Fees<input type="hidden" name="paypal_fees_account_id" value="<?php esc_attr_e( $paypal_fees_account_id ); ?>"></td>
					<td><span class="dashicons dashicons-arrow-right-alt"></span></td>
					<?php
					echo '<td class="edd-wave-remote-value">';
					echo '<select name="paypal_fees_select" class="edd-wave-select" data-selected="' . $paypal_fees_account_id . '">';
					echo $option_html_output;
					echo '</select>';
					echo '</td>';
					?>
				</tr>

			<?php } ?>

			</tbody>
		</table>

		<?php
	}

	/**
	 * Save EDD Wave admin settings
	 *
	 */
	public function save_settings() {

		// Quick nonce check
		if ( ! wp_verify_nonce( $_POST['edd_wave_settings_nonce'], 'edd-wave-settings' ) ) {
			error_log( 'EDD + Wave: bad nonce' );
			wp_die();
		}

		// One more check, then save settings
		if ( isset( $_POST['edd-wave-submit'] ) ) {

			// Get existing settings
			$settings = (array) get_option( 'edd_wave', [] );

			if ( isset( $_POST['business_id'] ) ) {
				$settings['business_id'] = sanitize_text_field( $_POST['business_id'] );
			}
			if ( isset( $_POST['api_key'] ) ) {
				$settings['api_key'] = sanitize_text_field( $_POST['api_key'] );
			}
			if ( isset( $_POST['paypal_anchor_account_id'] ) ) {
				$settings['paypal_anchor_account_id'] = sanitize_text_field( $_POST['paypal_anchor_account_id'] );
			}
			if ( isset( $_POST['paypal_fees_account_id'] ) ) {
				$settings['paypal_fees_account_id'] = sanitize_text_field( $_POST['paypal_fees_account_id'] );
			}
			if ( isset( $_POST['stripe_anchor_account_id'] ) ) {
				$settings['stripe_anchor_account_id'] = sanitize_text_field( $_POST['stripe_anchor_account_id'] );
			}
			if ( isset( $_POST['stripe_fees_account_id'] ) ) {
				$settings['stripe_fees_account_id'] = sanitize_text_field( $_POST['stripe_fees_account_id'] );
			}
			if ( isset( $_POST['expense_discounts'] ) ) {
				$settings['expense_discounts'] = sanitize_text_field( $_POST['expense_discounts'] );
			}

			if ( isset( $_POST['edd_wave_income'] ) ) {

				$variable_accounts = [];

				foreach( $_POST['edd_wave_income'] as $index => $income_account_array ) { // $index is the EDD parent download ID

					foreach( $income_account_array as $key => $income_account ) { // $key 0 is the parent, any other $keys are variable prices

						$account = sanitize_text_field( $income_account );

						if ( $key === 'parent' ) {
							update_post_meta( $index, '_wave_income_account', $account );
						} else {
							if ( ! empty( $account ) ) {
								$variable_accounts[$key] = $account;
							} else {
								unset( $variable_accounts[$key] );
							}
						}
					}

					if ( ! empty( $variable_accounts ) ) {
						// EDD variable priced product
						update_post_meta( $index, '_wave_variable_income_account', $variable_accounts );
						unset( $variable_accounts );
					}

				}

			}

			update_option( 'edd_wave', $settings );

		}
		wp_safe_redirect( admin_url( 'admin.php?page=edd-wave' ) );
		exit;

	}

} // end EDD_Wave_Settings