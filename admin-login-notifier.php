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

		wp_mail( $email_address, $subject, $message );
	}

	// Okay, carry on with the login attempt
	return $null;
}
add_filter( 'authenticate', 'admin_login_notifier', 10, 3 );