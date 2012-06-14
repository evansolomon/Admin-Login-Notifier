<?php
/*
Plugin Name: Admin Login Notifier
Description: Let's see what passwords bots try to use when they login as admin.
Author: evansolomon
Author URI: http://evansolomon.me
License: GPLv2 or later
*/

function admin_login_notifier( $null, $username, $password ) {
	// Did someone try to log in with admin?
	if ( 'admin' != $username )
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
add_filter( 'authenticate', 'admin_login_notifier', 10, 3 );

function aln_submenu() {
	add_submenu_page( 'tools.php', 'Admin Login Notifier', 'Admin Login Notifier', 'manage_options', 'admin-login-notifier', 'aln_submenu_ui' );
}
add_action( 'admin_menu', 'aln_submenu' );

function aln_submenu_ui() {
	global $title;
	if ( !current_user_can( 'manage_options' ) )
		wp_die( 'You do not have sufficient permissions to access this page.' );

	// Reset the counter of failed attempts
	update_option( 'aln_login_attempts_since_viewed', 0 );

	// Page header
	echo sprintf( '<div id="admin-login-notifier" class="wrap">%s<h2>%s</h2></div>', get_screen_icon( 'tools' ), esc_html( $title ) );

	// Show login attempts
	$alerts = get_option( 'aln_login_attempts' );
	if ( !$alerts || !is_array( $alerts ) )
		return;

	echo '<table>';
	echo '<tr><th>Date</th><th>Password</th></tr>';
	foreach ( $alerts as $alert ) {
		if ( !$alert )
			continue;
		echo sprintf( '<tr><td>%s</td><td style="padding-left:30px;">%s</td></tr>', date( 'M d, Y', $alert['time'] ), esc_html( $alert['password'] ) );
	}
	echo '</table>';
}

function aln_activation() {
	wp_schedule_event( current_time( 'timestamp' ), 'daily',  'aln_send_daily_email' );
}
register_activation_hook( __FILE__, 'aln_activation' );

function aln_deactivation(){
	wp_clear_scheduled_hook( 'aln_send_daily_email' );
}
register_deactivation_hook( __FILE__, 'aln_deactivation' );

function aln_send_daily_email() {
	// Get latest updates
	$aln_login_attempts = get_option( 'aln_login_attempts' );
	$new_attempts = array();
	foreach ( $aln_login_attempts as $attempt ) {
		if ( $attempt['time'] > time() - ( 60 * 60 * 24 ) )
			$new_attempts[] = $attempt['password'];
	}

	if( ! $new_attempts )
		return;

	// Who should we tell?
	$user_args = array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'user_email' ),
	);
	$user = get_users( $user_args );

	// Make sure we got an email address...
	if ( $user && $user[0] && $user[0]->user_email && is_email( $user[0]->user_email ) ) {
		//Now tell them!
		$email_address = $user[0]->user_email;

		$subject = "Today's admin login attempts";

		$message = "In the last day, someone tried to log into " . esc_url( home_url() ) . " as 'admin' " . esc_html( count( $new_attempts ) ) ." times.\n\n";
		$message .= "They used the passwords: \n\n";
		foreach ( $new_attempts as $new_attempt )
			$message .= esc_html( $new_attempt ) . "\n";
		$message .= "\nSilly bots!";

		$sent = wp_mail( $email_address, $subject, $message );

		// Update the since last viewed count
		if ( $sent )
			update_option( 'aln_login_attempts_since_viewed', 0 );
	}
}
add_action( 'aln_send_daily_email', 'aln_send_daily_email' );
