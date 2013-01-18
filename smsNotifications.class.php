<?php

/**
 * Load via GBS Add-On API
 */
class Group_Buying_SMS_Notifier_Addon extends Group_Buying_Controller {

	public static function init() {
		// Hook this plugin into the GBS add-ons controller
		add_filter( 'gb_addons', array( get_class(), 'gb_addon' ), 10, 1 );
	}

	public static function gb_addon( $addons ) {
		$addons['sms_notifier'] = array(
			'label' => self::__( 'SMS Notifier' ),
			'description' => self::__( 'Creates and sends SMS notifications for GBS. Add a new registration field to capture mobile number and make it required by default.' ),
			'files' => array(
				__FILE__,
			),
			'callbacks' => array(
				array( 'Group_Buying_SMS_Notifier', 'init' ),
				array( 'Mobile_Registration_Fields', 'init' ),
			),
		);
		return $addons;
	}

}

class Group_Buying_SMS_Notifier extends Group_Buying_Controller {

	const NOTIFICATION_SENT_META_KEY = 'voucher_notification';
	const NOTIFICATION_TYPE = 'gb_sms_notifications_sent';
	const ACCOUNT = 'gb_twilio_account';
	const AUTH = 'gb_twilio_auth';
	const NUMBER = 'gb_twilio_number';
	private static $twilio_number;
	private static $twilio_account;
	private static $twilio_auth;

	public static function init() {
		self::$twilio_account = get_option( self::ACCOUNT, '' );
		self::$twilio_auth = get_option( self::AUTH, '' );
		self::$twilio_number = get_option( self::NUMBER, '' );

		// Register Notifications
		add_filter( 'gb_notification_types', array( get_class(), 'register_notification_type' ), 10, 1 );
		//add_filter( 'gb_notification_shortcodes', array( get_class(), 'register_notification_shortcode' ), 10, 1 );

		// Actions to send alerts
		add_action( 'voucher_activated', array( get_class(), 'voucher_notification' ), 10, 1 );

		// Options
		add_action( 'admin_init', array( get_class(), 'register_settings_fields' ), 10, 0 );

		// Add mobile to registration and edit
		// TODO look at extra registration fields addon
	}

	public function register_notification_type( $notifications ) {
		$notifications[self::NOTIFICATION_TYPE] = array(
			'name' => self::__( 'SMS Alert: Voucher Available' ),
			'description' => self::__( "Customize the SMS notification that is sent when a user's voucher is activated." ),
			'shortcodes' => array( 'date', 'site_title', 'site_url', 'deal_url', 'deal_title', 'voucher_url' ),
			'default_title' => self::__( 'SMS Alert: Voucher Available' ),
			'default_content' => ''
		);
		return $notifications;
	}

	public function register_notification_shortcode( $default_shortcodes ) {
		$default_shortcodes['voucher_url'] = array(
			'description' => self::__( 'Used to return the voucher url.' ),
			'callback' => array( get_class(), 'voucher_url_shortcode' )
		);
		return $default_shortcodes;
	}

	public static function voucher_url_shortcode( $atts, $content, $code, $data ) {
		// Get the deal_id and permalink, then add the anchor
	}

	public function voucher_notification( $voucher ) {
		$voucher_id = $voucher->get_id();
		$purchase = $voucher->get_purchase();
		$account_id = $purchase->get_account_id();

		// Check if already sent
		$alerts_sent = get_post_meta( $account_id, self::NOTIFICATION_SENT_META_KEY );
		if ( is_array( $alerts_sent ) && in_array( $voucher_id, $alerts_sent ) ) {
			return;
		}

		$account = Group_Buying_Account::get_instance_by_id( $account_id );
		if ( !is_a( $account, 'Group_Buying_Account' ) )
			return;

		$user_id = $account->get_user_id();
		if ( $user_id !== -1 ) {
			$purchase = $voucher->get_purchase();
			$deal = $voucher->get_deal();
			// Get the mobile number
			// $email = self::get_user_email( $user_id );
			$mobile_number = Mobile_Registration_Fields::get_mobile_number( $account );
			$formatted_mobile_number = '+'.preg_replace( "/[^0-9]/", '', $mobile_number );

			if ( strlen( $formatted_mobile_number ) < 10 ) {
				return;
			}

			// Run the data through notifications to get the message.
			$data = array(
				'user_id' => $user_id,
				'voucher' => $voucher,
				'purchase' => $purchase,
				'deal' => $deal
			);
			$message = Group_Buying_Notifications::get_notification_content( self::NOTIFICATION_TYPE, $data );

			error_log( "mesage: " . print_r( $message, true ) );
			// And send
			self::send_sms( $formatted_mobile_number, $message );
			// Log that the message was sent
			add_post_meta( $account_id, self::NOTIFICATION_SENT_META_KEY, $voucher_id );
		}
	}

	public function send_sms( $recipient, $message ) {
		// TODO use JSON API
		require 'twilio-php/Services/Twilio.php';

		$account_sid = self::$twilio_account; // Your Twilio account sid
		$auth_token = self::$twilio_auth; // Your Twilio auth token
		$twilio_number = '+'.preg_replace( "/[^0-9]/", '', self::$twilio_number ); // Your Twilio auth token
		error_log( "sid: " . print_r( $account_sid, true ) );
		error_log( "auth token: " . print_r( $auth_token, true ) );
		error_log( "number: " . print_r( $twilio_number, true ) );
		$twilio_client = new Services_Twilio( $account_sid, $auth_token );
		$message = $twilio_client->account->sms_messages->create(
			$twilio_number, // From a Twilio number in your account
			$recipient,
			$message
		);
		error_log( "message cleint: " . print_r( $message , true ) );
	}

	public static function register_settings_fields() {
		$page = Group_Buying_UI::get_settings_page();
		$section = 'gb_twilio_settings';
		add_settings_section( $section, self::__( 'SMS Alerts: Twilio settings' ), array( get_class(), 'display_settings_section' ), $page );
		// Settings
		register_setting( $page, self::ACCOUNT );
		register_setting( $page, self::AUTH );
		register_setting( $page, self::NUMBER );
		// Fields
		add_settings_field( self::ACCOUNT, self::__( 'Twilio Account SID' ), array( get_class(), 'display_twilio_auth_option' ), $page, $section );
		add_settings_field( self::AUTH, self::__( 'Twilio Authentication Token' ), array( get_class(), 'display_twilio_account_option' ), $page, $section );
		add_settings_field( self::NUMBER, self::__( 'From Number: Twilio number in your account' ), array( get_class(), 'display_twilio_number_option' ), $page, $section );
	}

	public static function display_twilio_account_option() {
		echo '<input name="'.self::AUTH.'" id="'.self::AUTH.'" type="text" value="'.self::$twilio_account.'">';
	}

	public static function display_twilio_auth_option() {
		echo '<input name="'.self::ACCOUNT.'" id="'.self::ACCOUNT.'" type="text" value="'.self::$twilio_auth.'">';
	}

	public static function display_twilio_number_option() {
		echo '<input name="'.self::NUMBER.'" id="'.self::NUMBER.'" type="text" placeholder="+XXXXXXXXXXX" value="'.self::$twilio_number.'">';
	}
}


class Mobile_Registration_Fields extends Group_Buying_Controller {

	// List all of your field IDs here as constants
	const MOBILE_NUMBER = 'gb_account_fields_mobile';

	public static function init() {

		// registration hooks
		add_filter( 'gb_account_registration_panes', array( get_class(), 'get_registration_panes' ), 100 );
		add_filter( 'gb_validate_account_registration', array( get_class(), 'validate_account_fields' ), 10, 4 );
		add_action( 'gb_registration', array( get_class(), 'process_registration' ), 50, 5 );

		// Add the options to the account edit screens
		add_filter( 'gb_account_edit_panes', array( get_class(), 'get_edit_fields' ), 0, 2 );
		add_action( 'gb_process_account_edit_form', array( get_class(), 'process_edit_account' ) );

		// Hook into the reports
		add_filter( 'set_deal_purchase_report_data_column', array( get_class(), 'reports_columns' ), 10, 2 );
		add_filter( 'set_merchant_purchase_report_column', array( get_class(), 'reports_columns' ), 10, 2 );
		add_filter( 'set_accounts_report_data_column', array( get_class(), 'reports_columns' ), 10, 2 );
		add_filter( 'gb_deal_purchase_record_item', array( get_class(), 'reports_record' ), 10, 3 );
		add_filter( 'gb_merch_purchase_record_item', array( get_class(), 'reports_record' ), 10, 3 );
		add_filter( 'gb_accounts_record_item', array( get_class(), 'reports_account_record' ), 10, 3 );

	}

	/**
	 * Add the report coloumns.
	 *
	 * @param array
	 * @return null
	 */
	public function reports_columns( $columns ) {
		// Add as many as you want with their own key that will be used later.
		$columns['rf_mobile_number'] = self::__( 'Mobile' );
		return $columns;
	}

	/**
	 * Add the report record for deal purchase and merchant report.
	 *
	 * @param array
	 * @return null
	 */
	public function reports_record( $array, $purchase, $account ) {
		if ( !is_a( $account, 'Group_Buying_Account' ) ) {
			return $array;
		}
		// Add as many as you want with their own matching key from the reports_column
		$array['rf_mobile_number'] = get_post_meta( $account->get_ID(), '_'.self::MOBILE_NUMBER, TRUE );
		return $array;
	}

	/**
	 * Add the report record for account report
	 *
	 * @param array
	 * @return null
	 */
	public function reports_account_record( $array, $account ) {
		// Add as many as you want with their own matching key from the reports_column
		$array['rf_mobile_number'] = get_post_meta( $account->get_ID(), '_'.self::MOBILE_NUMBER, TRUE );
		return $array;
	}

	/**
	 * Hook into the process registration action
	 *
	 * @param array
	 * @return null
	 */
	public function process_registration( $user = null, $user_login = null, $user_email = null, $password = null, $post = null ) {
		$account = Group_Buying_Account::get_instance( $user->ID );
		// using the single callback below
		self::process_form( $account );
	}

	/**
	 * Hook into the process edit account action
	 *
	 * @param array
	 * @return null
	 */
	public static function process_edit_account( Group_Buying_Account $account ) {
		// using the single callback below
		self::process_form( $account );
	}

	/**
	 * Process the form submission and save the meta
	 *
	 * @param array   | Group_Buying_Account
	 * @return null
	 */
	public static function process_form( Group_Buying_Account $account ) {
		// Copy all of the new fields below, copy the below if it's a basic field.
		if ( isset( $_POST[self::MOBILE_NUMBER] ) && $_POST[self::MOBILE_NUMBER] != '' ) {
			// TODO check length and throw and error

			delete_post_meta( $account->get_ID(), '_'.self::MOBILE_NUMBER );
			add_post_meta( $account->get_ID(), '_'.self::MOBILE_NUMBER, $_POST[self::MOBILE_NUMBER] );
		}
		// Below is a commented out process to uploaded images
		/*/
		if ( !empty($_FILES[self::UPLOAD]) ) {
		 	// Set the uploaded field as an attachment
			self::set_attachement( $account->get_ID(), $_FILES );
		}
		/**/
	}

	/**
	 * Add a file as a post attachment.
	 *
	 * @return null
	 */
	public static function set_attachement( $post_id, $files ) {
		if ( !function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin' . '/includes/image.php';
			require_once ABSPATH . 'wp-admin' . '/includes/file.php';
			require_once ABSPATH . 'wp-admin' . '/includes/media.php';
		}
		foreach ( $files as $file => $array ) {
			if ( $files[$file]['error'] !== UPLOAD_ERR_OK ) {
				self::set_message( 'upload error : ' . $files[$file]['error'] );
			}
			$attach_id = media_handle_upload( $file, $post_id );
		}
		// Make it a thumbnail while we're at it.
		if ( $attach_id > 0 ) {
			update_post_meta( $post_id, '_thumbnail_id', $attach_id );
		}
		return $attach_id;
	}

	/**
	 * Validate the form submitted
	 *
	 * @return array
	 */
	public function validate_account_fields( $errors, $username, $email_address, $post ) {
		// If the field is required it should
		if ( isset( $post[self::MOBILE_NUMBER] ) && $post[self::MOBILE_NUMBER] == '' ) {
			$errors[] = self::__( '"Mobile Number" is required.' );
		}
		return $errors;
	}

	/**
	 * Add the default pane to the account edit form
	 *
	 * @param array   $panes
	 * @return array
	 */
	public function get_registration_panes( array $panes ) {
		$panes['mobile_fields'] = array(
			'weight' => 99.9,
			'body' => self::rf_load_view_string( 'panes', array( 'fields' => self::fields() ) ),
		);
		return $panes;
	}

	/**
	 * Add the fields to the registration form
	 *
	 * @param Group_Buying_Account $account
	 * @return array
	 */
	private function fields( $account = NULL ) {
		$fields = array(
			'mobile' => array(
				'weight' => 0, // sort order
				'label' => self::__( 'Mobile Number' ), // the label of the field
				'type' => 'text', // type of field (e.g. text, textarea, checkbox, etc. )
				'required' => FALSE, // If this is false then don't validate the post in validate_account_fields
				'placeholder' => 'X-XXX-XXX-XXXX' // the default value
			),
			// add new fields here within the current array.
		);
		$fields = apply_filters( 'custom_registration_fields', $fields );
		return $fields;
	}

	/**
	 * Add the default pane to the account edit form
	 *
	 * @param array   $panes
	 * @param Group_Buying_Account $account
	 * @return array
	 */
	public function get_edit_fields( array $panes, Group_Buying_Account $account ) {
		$panes['mobile_fields'] = array(
			'weight' => 50,
			'body' => self::rf_load_view_string( 'panes', array( 'fields' => self::edit_fields( $account ) ) ),
		);
		return $panes;
	}


	/**
	 * Add the fields to the account form
	 *
	 * @param Group_Buying_Account $account
	 * @return array
	 */
	private function edit_fields( $account = NULL ) {
		$fields = array(
			'mobile' => array(
				'weight' => 0, // sort order
				'label' => self::__( 'Mobile Number' ), // the label of the field
				'type' => 'text', // type of field (e.g. text, textarea, checkbox, etc. )
				'required' => FALSE, // If this is false then don't validate the post in validate_account_fields
				'placeholder' => 'X-XXX-XXX-XXXX', // the default value
				'default' => self::get_mobile_number( $account )
			),
			// add new fields here within the current array.
		);
		uasort( $fields, array( get_class(), 'sort_by_weight' ) );
		$fields = apply_filters( 'invite_only_fields', $fields );
		return $fields;
	}

	/**
	 * return a view as a string.
	 *
	 */
	private static function rf_load_view_string( $path, $args ) {
		ob_start();
		if ( !empty( $args ) ) extract( $args );
		@include 'views/'.$path.'.php';
		return ob_get_clean();
	}

	public function get_mobile_number( Group_Buying_Account $account ) {
		return get_post_meta( $account->get_ID(), '_'.self::MOBILE_NUMBER, TRUE );
	}
}