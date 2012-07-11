<?php
/*
Plugin Name: Admin Login Notifier
Description: Lots of bots (or just rude people) try to login to WordPress sites as the user 'admin'. This plugin records the passwords they use so you can see what sort of nonsense is out there. Mostly it's just fun, but in theory I guess it could inform some sort of real-time security thing. But yea, mostly it's just for fun. By the way, in case you didn't realize it yet, you shouldn't use the username 'admin'.
Author: evansolomon
Author URI: http://evansolomon.me
License: GPLv2 or later
Version: 2.1
*/

class Admin_Login_Notifier {
	const version = 2.1;

	// Setup hooks
	function __construct() {
		// (De)Activation hooks
		register_activation_hook(   __FILE__, array( $this, 'schedule_cron'  ) );
		register_activation_hook(   __FILE__, array( $this, 'create_options' ) );
		register_deactivation_hook( __FILE__, array( $this, 'clear_cron'     ) );

		// Version-specific
		add_action( 'init', array( $this, 'check_version_update' ) );

		// Hooks
		add_filter( 'authenticate'        , array( $this, 'check_login_attempt' ) , 10, 3 );
		add_action( 'admin_menu'          , array( $this, 'submenu' )             , 10, 0 );
		add_action( 'aln_send_daily_email', array( $this, 'aln_send_daily_email' ), 10, 0 );
	}

	// Check each login attempt to see if it's for the 'admin' user
	function check_login_attempt( $null, $username, $password ) {
		// Did someone try to log in as admin?
		if ( apply_filters( 'aln_username', 'admin' ) != $username )
			return $null;

		// Save the password they tried
		$alerts = (array) get_option( 'aln_login_attempts' );
		$alerts[] = array( 'time' => time(), 'password' => $password );
		update_option( 'aln_login_attempts', $alerts );

		// Bump a counter of failed attempts
		$attempts_since_viewed = (int) get_option( 'aln_login_attempts_since_viewed' );
		update_option( 'aln_login_attempts_since_viewed', ++$attempts_since_viewed );

		// Okay, carry on with the login attempt
		return $null;
	}

	// Register the plugin's menu under the Tools panel
	function submenu() {
		$submenu_hookname = add_submenu_page(
			'tools.php',
			esc_html( __( 'Admin Login Notifier' ) ),
			esc_html( __( 'Admin Login Notifier' ) ),
			apply_filters( 'aln_menu_cap', 'manage_options' ),
			'admin-login-notifier',
			array( $this, 'submenu_ui' )
		);

		return $submenu_hookname;
	}

	// Build the menu UI
	function submenu_ui() {
		global $title;

		if ( ! current_user_can( apply_filters( 'aln_cap_level', 'manage_options' ) ) )
			wp_die( esc_html( __( 'You do not have sufficient permissions to access this page.' ) ) );

		// Reset the counter of failed attempts
		update_option( 'aln_login_attempts_since_viewed', 0 );

		// Page header
		echo sprintf( '<div id="admin-login-notifier" class="wrap">%s<h2>%s</h2></div>', get_screen_icon( 'tools' ), esc_html( $title ) );

		// Show login attempts
		$alerts = get_option( 'aln_login_attempts' );
		if ( ! $alerts || ! is_array( $alerts ) )
			return false;

		echo '<table>';
		echo sprintf(
			'<tr><th>%s</th><th>%s</th></tr>',
			esc_html( __( 'Date' ) ),
			esc_html( __( 'Password' ) )
		);

		foreach ( $alerts as $alert ) {
			if ( ! $alert )
				continue;
			echo sprintf( '<tr><td>%s</td><td style="padding-left:30px;">%s</td></tr>', date( 'M d, Y', $alert['time'] ), esc_html( $alert['password'] ) );
		}

		echo '</table>';

		return true;
	}

	// Schedule daily emails to be sent when there are new login attempts
	function schedule_cron() {
		return wp_schedule_event( current_time( 'timestamp' ), 'daily',  'aln_send_daily_email' );
	}

	function create_options() {
		add_option( 'aln_login_attempts', array(), '', 'no' );
		add_option( 'aln_login_attempts_since_viewed', array(), '', 'no' );
	}

	// Remove the scheduled daily email
	function clear_cron() {
		// wp_clean_scheduled_hook() doesn't return a meaningful value, so neither does this function
		wp_clear_scheduled_hook( 'aln_send_daily_email' );
	}

	// Send an email with the last day's attempts
	function aln_send_daily_email() {
		// Get latest updates
		$aln_login_attempts = get_option( 'aln_login_attempts' );
		$new_attempts = array();
		foreach ( $aln_login_attempts as $attempt ) {
			if ( $attempt['time'] > time() - ( 60 * 60 * 24 ) )
				$new_attempts[] = $attempt['password'];
		}

		if( ! $new_attempts )
			return false;

		// Who should we tell?
		$user_args = array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => array( 'user_email' ),
		);
		$user = apply_filters( 'aln_send_daily_email_user', get_users( $user_args ) );

		// Make sure we got an email address...
		if ( ! $user || ! $user[0] || ! $user[0]->user_email || ! is_email( $user[0]->user_email ) )
			return false;

		//Now tell them!
		$email_address = apply_filters( 'aln_send_daily_email_address', $user[0]->user_email );

		$subject = apply_filters( 'aln_send_daily_email_subject', esc_html( __( "Today's admin login attempts" ) ) );

		$message = sprintf(
			esc_html( __( 'In the last day, someone tried to log into %1$s as "admin" %2$d %3$s.' ) ),
			esc_url( home_url() ),
			number_format_i18n( count( $new_attempts ) ),
			_n( 'time', 'times', count( $new_attempts ) )
		);
		$message .= "\n\n";

		$message .= esc_html( __( 'They used the passwords:' ) );
		$message .= "\n\n";

		foreach ( $new_attempts as $new_attempt )
			$message .= esc_html( $new_attempt ) . "\n";

		$message .= "\n";
		$message .= esc_html( __( 'Silly bots!' ) );
		$message = apply_filters( 'aln_send_daily_email_message', $message );

		$sent = wp_mail( $email_address, $subject, $message );

		// Update the since last viewed count
		if ( ! $sent )
			return false;

		// Optionally delete saved attempts once they're emailed
		if ( ! apply_filters( 'aln_save_all_login_attempts', true ) )
			delete_option( 'aln_login_attempts' );

		update_option( 'aln_login_attempts_since_viewed', 0 );
		return true;
	}

	function check_version_update() {
		$current_version = get_option( 'aln_current_version' );
		if( self::version == $current_version )
			return;

		// Un-autoload these options
		$aln_login_attempts = get_option( 'aln_login_attempts' );
		delete_option( 'aln_login_attempts' );
		add_option( 'aln_login_attempts', $aln_login_attempts, '', 'no' );

		$aln_login_attempts_since_viewed = get_option( 'aln_login_attempts_since_viewed' );
		delete_option( 'aln_login_attempts_since_viewed' );
		add_option( 'aln_login_attempts_since_viewed', $aln_login_attempts_since_viewed, '', 'no' );

		// Set the current version
		add_option( 'aln_current_version', self::version );
	}
}

new Admin_Login_Notifier;
