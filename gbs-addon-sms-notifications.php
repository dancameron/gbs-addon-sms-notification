<?php
/*
Plugin Name: Group Buying Addon - SMS Notifier
Version: 1
Plugin URI: http://groupbuyingsite.com/marketplace
Description: Creates and sends SMS notifications for GBS
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Text Domain: group-buying
*/


// Load after all other plugins since we need to be compatible with groupbuyingsite
add_action('plugins_loaded', 'gb_sms_notifier');
function gb_sms_notifier() {
	if ( class_exists('Group_Buying_Controller') ) {
		require_once('smsNotifications.class.php');
		Group_Buying_SMS_Notifier_Addon::init();
	}
}