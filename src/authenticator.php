<?php

class Memberful_Authenticator {
	/**
	 * Gets the url for the specified action at the member OAuth endpoint
	 *
	 * @param string $action Action to access at endpoint
	 * @return string URL
	 */
	static public function oauth_member_url( $action = '' ) {
		return memberful_url( 'oauth/'.$action );
	}

	/**
	 * Authentication for subscribers is handled by Memberful.
	 * Prevent subscribers from requesting password resets
	 *
	 * @return boolean
	 */
	static public function audit_password_reset( $allowed, $user_id ) {
		$user = new WP_User( $user_id );

		return $user->has_cap( 'subscriber' ) ? FALSE : $allowed;
	}

	/**
	 * Returns the url of the endpoint that members will be sent to
	 *
	 * @return string
	 */
	static function oauth_auth_url() {
		$params = array(
			'response_type' => 'code',
			'client_id'     => get_option( 'memberful_client_id' ),
		);

		return add_query_arg( $params, self::oauth_member_url() );
	}

	/**
	 * @var WP_Error Errors encountered
	 */
	protected $_wp_error = NULL;

	protected function _error( $code, $error = NULL ) {
		$message = array(
		  "We had a problem signing you in, please try again later or contact the site admin."
		);

		if ( is_wp_error($error) ) {
			$message = array_merge($message, $error->get_error_messages());
		} elseif ( ! empty($error) ) {
			array_push($message, htmlentities((string) $error, ENT_QUOTES));
		}

		array_push($message, htmlentities($code));

		wp_die(implode($message, '<br/>'));
	}

	/**
	 * Callback for the `authenticate` hook.
	 *
	 * Called in wp-login.php when the login form is rendered, thus it responds
	 * to both GET and POST requests.
	 *
	 * @return WP_User The user to be logged in or NULL if user couldn't be
	 * determined
	 */
	public function init( $user, $username, $password ) {
		// If another authentication system has handled this request
		if ( $user instanceof WP_User ) {
			return $user;
		}

		// This is the OAuth response
		if ( isset( $_GET['code'] ) ) {
			$tokens = $this->get_oauth_tokens( $_GET['code'] );

			$account = $this->get_member_data( $tokens->access_token );

			return memberful_wp_sync_member_from_memberful_account(
				$account,
				array( 'refresh_token' => $tokens->refresh_token )
			);
		} elseif ( isset( $_GET['error'] ) ) {
			// For some reason we got an error code.
			return $this->_error(
				'memberful_oauth_error',
				$_GET['error']
			);
		}


		// Store where the user came from in a cookie
		$expire  = time() + ( 30 * 60 ); // 30 minutes
		$referer = $_SERVER['HTTP_REFERER'];

		// Allow overriding of redirect location
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$referer = $_REQUEST['redirect_to'];
		}

		setcookie( 'memberful_redirect', $referer, $expire, '/', COOKIE_DOMAIN, is_ssl(), true );


		// Send the user to Memberful
		wp_redirect( self::oauth_auth_url(), 302 );
		exit();
	}


	public function hook_into_wordpress() {
		add_filter( 'authenticate', array( $this, 'init' ), 10, 3 );
	}


	/**
	 * login_redirect filter
	 * Should redirect to where the user came from before he clicked the login button
	 */
	public function redirect( $redirect, $request_redirect, $user ) {
		// Not enabled so return default
		if ( ! memberful_wp_oauth_enabled() ) {
			return $redirect;
		}

		return $redirect_to;
	}

	/**
	 * Gets the access token and refresh token from an authorization code
	 *
	 * @param string $auth_code The authorization code returned from OAuth endpoint
	 * @return StdObject Access token and Refresh token
	 */
	public function get_oauth_tokens( $auth_code ) {
		$params = array(
			'client_id'     => get_option( 'memberful_client_id' ),
			'client_secret' => get_option( 'memberful_client_secret' ),
			'grant_type'    => 'authorization_code',
			'code'          => $auth_code,
		);
		$response = memberful_wp_post_data_to_api_as_json( self::oauth_member_url('token'), 'get_oauth_tokens', $params );

		if ( is_wp_error($response) ) {
			return $this->_error( 'could_not_get_tokens', $response );
		}

		$body = json_decode( $response['body'] );
		$code = $response['response']['code'];

		if ( $code != 200 OR $body === NULL OR empty( $body->access_token ) ) {
			return $this->_error(
				'oauth_access_fail',
				'Could not get access token from Memberful'
			);
		}

		return json_decode( $response['body'] );
	}

	/**
	 * Gets information about a user from Memberful.
	 *
	 * @param string $access_token An access token which can be used to get info
	 * about the member
	 * @return array
	 */
	public function get_member_data( $access_token ) {
		$url = memberful_account_url( MEMBERFUL_JSON );

		$response = memberful_wp_get_data_from_api(
			add_query_arg( 'access_token', $access_token, $url ),
			'get_member_data_for_sign_in'
		);

		if ( is_wp_error( $response ) ) {
			return $this->_error( 'fetch_account_connect_failure', $response );
		}

		$body = json_decode( $response['body'] );
		$code = $response['response']['code'];

		if ( $code != 200 OR $body === NULL ) {
			return $this->_error( 'fetch_account_response_failure', 'Could not fetch your data from Memberful. '.$code );
		}

		return $body;
	}
}

// Backup, prevent members from resetting their password
add_filter( 'allow_password_reset', array( 'Memberful_Authenticator', 'audit_password_reset' ), 50, 2 );
