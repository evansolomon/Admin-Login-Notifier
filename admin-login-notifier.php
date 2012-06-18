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
		$subject = 'Admin login attempt!';
		$message = "Someone just tried to log into " . home_url() . " as \"admin\"\n\n";
		$message .= "They used the password: {$password}\n\n";
		$message .= "Silly bots!";

		$alerts = get_option( 'aln_login_attempts' );
		$alerts = (array) $alerts;
		$alerts[] = array( 'time' => time(), 'password' => $password );
		update_option( 'aln_login_attempts', $alerts );

		wp_mail( $email_address, $subject, $message );
	}

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

	// Page header
	echo sprintf( '<div id="vip-scanner" class="wrap">%s<h2>%s</h2></div>', get_screen_icon( 'tools' ), esc_html( $title ) );

	$alerts = get_option( 'aln_login_attempts' );
	if( !$alerts || !is_array( $alerts ) )
		return;

	echo '<table>';
	echo '<tr><th>Date</th><th>Password</th></tr>';
	foreach( $alerts as $alert ) {
		if( !$alert )
			continue;
		echo sprintf( '<tr><td>%s</td><td>%s</td></tr>', date( 'M d, Y', $alert['time'] ), esc_html( $alert['password'] ) );
	}
	echo '</table>';
}