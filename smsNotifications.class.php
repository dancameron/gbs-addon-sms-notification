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
			'description' => self::__( 'Creates and sends SMS notifications for GBS.' ),
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

	const ACCOUNT = 'gb_twilio_account';
	const AUTH = 'gb_twilio_auth';
	const NUMBER = 'gb_twilio_number';
	private static $twilio_number;
	private static $twilio_account;
	private static $twilio_auth;

	public static function init() {
		self::$twilio_auth = get_option( self::ACCOUNT, '' );
		self::$twilio_account = get_option( self::AUTH, '' );
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
			'name' => self::__( 'SMS Voucher Alert' ),
			'description' => self::__( "Customize the SMS notification that is sent when a user's voucher is activated." ),
			'shortcodes' => array( 'date', 'site_title', 'site_url', 'deal_url', 'deal_title', 'voucher_url' ),
			'default_title' => self::__( 'SMS Alert: Voucher Available' ),
			'default_content' => self::default_update_content()
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
		$purchase = $voucher->get_purchase();
		$account_id = $voucher->get_account_id();
		$account = Group_Buying_Account::get_instance_by_id( $account_id );
		$user_id = $account->get_user_id();
		$purchase = $voucher->get_purchase();
		$deal = $voucher->get_deal();

		if ( is_a( $account, 'Group_Buying_Account' ) && $user_id !== -1 ) {
			// Get the mobile number
			$recipient = ;
			$email = self::get_user_email( $user_id );

			$data = array(
				'user_id' => $user_id,
				'voucher' => $voucher,
				'purchase' => $purchase,
				'deal' => $deal
			);

			$message = Group_Buying_Notifications::get_notification_content( 'voucher_notification', $data );
			self::send_sms( $recipient, $message );
		}
	}

	public function send_sms( $recipient, $message ) {
		require 'twilio-php/Services/Twilio.php';

		$account_sid = self::$twilio_account; // Your Twilio account sid
		$auth_token = self::$twilio_auth; // Your Twilio auth token
		$twilio_number = self::$twilio_number; // Your Twilio auth token

		$twilio_client = new Services_Twilio( $account_sid, $auth_token );
		$message = $twilio_client->account->sms_messages->create(
			$twilio_number, // From a Twilio number in your account
			$recipient,
			$message
		);
	}

	public static function register_settings_fields() {
		$page = Group_Buying_UI::get_settings_page();
		$section = 'gb_twilio_settings';
		add_settings_section( $section, self::__( '' ), array( get_class(), 'display_settings_section' ), $page );
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
		echo '<input name="'.self::NUMBER.'" id="'.self::NUMBER.'" type="text" value="'.self::$twilio_number.'">';
	}
}

