<?php
/*
Plugin Name: Admin Login Notifier
Description: Let's see what passwords bots try to use when they login as admin.
Author: evansolomon
Author URI: http://evansolomon.me
License: GPLv2 or later
*/

class Admin_Login_Notifier {
	function __construct() {
		// (De)Activation hooks
		register_activation_hook(   __FILE__, array( $this, 'activation'   ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

		// Hooks
		add_filter( 'authenticate'        , array( $this, 'check_login_attempt' ) , 10, 3 );
		add_action( 'admin_menu'          , array( $this, 'submenu' )             , 10, 0 );
		add_action( 'aln_send_daily_email', array( $this, 'aln_send_daily_email' ), 10, 0 );
	}

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

	function activation() {
		return wp_schedule_event( current_time( 'timestamp' ), 'daily',  'aln_send_daily_email' );
	}

	function deactivation() {
		// wp_clean_scheduled_hook() doesn't return a meaningful value, so neither does this function
		wp_clear_scheduled_hook( 'aln_send_daily_email' );
	}

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

		update_option( 'aln_login_attempts_since_viewed', 0 );
		return true;
	}
}

$admin_login_notifier = new Admin_Login_Notifier;