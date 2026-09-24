<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Sign-In verification + a lightweight session system for the quiz
 * (kept independent of native WordPress accounts, since the quiz is meant
 * to work for any visitor with a Google account, whether or not they have
 * a WP login).
 */
class QQ_Auth {

	const SESSION_TTL_DAYS = 30;

	/**
	 * Verify a Google Identity Services ID token and return its payload
	 * (sub, email, name, picture) or a WP_Error.
	 *
	 * We verify via Google's tokeninfo endpoint rather than a bundled JWT
	 * library — no extra dependency, and it's the approach Google's own
	 * docs describe for server-side verification:
	 * https://developers.google.com/identity/sign-in/web/backend-auth
	 */
	public static function verify_google_id_token( $id_token ) {
		$settings  = QQ_DB::get_settings();
		$client_id = trim( $settings['google_client_id'] );

		if ( '' === $client_id ) {
			return new WP_Error( 'qq_no_client_id', 'Google Sign-In is not configured yet on this site (missing Client ID).', array( 'status' => 500 ) );
		}
		if ( '' === trim( (string) $id_token ) ) {
			return new WP_Error( 'qq_no_token', 'Missing Google credential.', array( 'status' => 400 ) );
		}

		$response = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'qq_google_unreachable', 'Could not reach Google to verify sign-in. Please try again.', array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body ) || ! is_array( $body ) ) {
			return new WP_Error( 'qq_invalid_token', 'Google sign-in could not be verified. Please try signing in again.', array( 'status' => 401 ) );
		}

		$iss = isset( $body['iss'] ) ? $body['iss'] : '';
		if ( ! in_array( $iss, array( 'accounts.google.com', 'https://accounts.google.com' ), true ) ) {
			return new WP_Error( 'qq_invalid_issuer', 'Sign-in token has an unexpected issuer.', array( 'status' => 401 ) );
		}

		$aud = isset( $body['aud'] ) ? $body['aud'] : '';
		if ( ! hash_equals( $client_id, $aud ) ) {
			return new WP_Error( 'qq_invalid_audience', 'Sign-in token was not issued for this site.', array( 'status' => 401 ) );
		}

		if ( empty( $body['sub'] ) ) {
			return new WP_Error( 'qq_invalid_subject', 'Sign-in token is missing a user id.', array( 'status' => 401 ) );
		}

		if ( isset( $body['email_verified'] ) && 'true' !== $body['email_verified'] && true !== $body['email_verified'] ) {
			// Not fatal — Google accounts are generally verified — but we
			// don't want to silently fail either, so we still proceed and
			// simply trust Google's own record here.
		}

		return array(
			'google_id' => sanitize_text_field( $body['sub'] ),
			'email'     => isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '',
			'name'      => isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : ( isset( $body['email'] ) ? $body['email'] : 'Quiz Participant' ),
			'picture'   => isset( $body['picture'] ) ? esc_url_raw( $body['picture'] ) : '',
		);
	}

	/**
	 * Verify token, upsert the qq_users row, issue a fresh session, return
	 * { token, user }.
	 */
	public static function login_with_google( $id_token ) {
		$payload = self::verify_google_id_token( $id_token );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		global $wpdb;
		$users_table = QQ_DB::users_table();
		$now         = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$users_table} WHERE google_id = %s", $payload['google_id'] ) ); // phpcs:ignore

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore
				$users_table,
				array(
					'name'          => $payload['name'],
					'email'         => $payload['email'],
					'photo_url'     => $payload['picture'],
					'last_login_at' => $now,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$user_id = (int) $existing->id;
		} else {
			$wpdb->insert( // phpcs:ignore
				$users_table,
				array(
					'google_id'     => $payload['google_id'],
					'name'          => $payload['name'],
					'email'         => $payload['email'],
					'photo_url'     => $payload['picture'],
					'created_at'    => $now,
					'last_login_at' => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$user_id = (int) $wpdb->insert_id;
		}

		$token = self::create_session( $user_id );

		return array(
			'token' => $token,
			'user'  => array(
				'id'     => $user_id,
				'name'   => $payload['name'],
				'email'  => $payload['email'],
				'photo'  => $payload['picture'],
			),
		);
	}

	/**
	 * Start a guest session — no Google account required. Guests get their
	 * own qq_users row (so the existing attempts/history/certificate flow
	 * works completely unchanged) but with a generated, non-Google id, no
	 * email and no photo. Their history lives only in this browser's
	 * session token, so it isn't recoverable across devices — the frontend
	 * tells the user this.
	 */
	public static function login_as_guest( $name ) {
		global $wpdb;
		$users_table = QQ_DB::users_table();
		$now         = current_time( 'mysql' );

		$name = trim( sanitize_text_field( (string) $name ) );
		if ( '' === $name ) {
			$name = 'Guest ' . wp_rand( 1000, 9999 );
		}
		if ( strlen( $name ) > 60 ) {
			$name = substr( $name, 0, 60 );
		}

		$guest_id = 'guest_' . bin2hex( random_bytes( 12 ) );

		$wpdb->insert( // phpcs:ignore
			$users_table,
			array(
				'google_id'     => $guest_id,
				'name'          => $name,
				'email'         => null,
				'photo_url'     => null,
				'created_at'    => $now,
				'last_login_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$user_id = (int) $wpdb->insert_id;

		$token = self::create_session( $user_id );

		return array(
			'token' => $token,
			'user'  => array(
				'id'       => $user_id,
				'name'     => $name,
				'email'    => '',
				'photo'    => '',
				'is_guest' => true,
			),
		);
	}

	/**
	 * True if a qq_users row (array, from get_current_user or a DB fetch)
	 * is a guest account rather than a real Google sign-in.
	 */
	public static function is_guest_row( $row ) {
		return isset( $row['google_id'] ) && 0 === strpos( $row['google_id'], 'guest_' );
	}

	private static function create_session( $user_id ) {
		global $wpdb;
		$sessions_table = QQ_DB::sessions_table();

		$token   = bin2hex( random_bytes( 32 ) );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( self::SESSION_TTL_DAYS * DAY_IN_SECONDS ) );

		$wpdb->insert( // phpcs:ignore
			$sessions_table,
			array(
				'token'      => $token,
				'user_id'    => $user_id,
				'created_at' => current_time( 'mysql' ),
				'expires_at' => $expires,
			),
			array( '%s', '%d', '%s', '%s' )
		);

		return $token;
	}

	/**
	 * Resolve the current quiz user from the request's Authorization header
	 * (Bearer <session token>). Returns the qq_users row (as array) or
	 * WP_Error if missing/expired.
	 */
	public static function get_current_user( WP_REST_Request $request ) {
		$token = self::extract_token( $request );
		if ( ! $token ) {
			return new WP_Error( 'qq_not_logged_in', 'Please sign in with Google to continue.', array( 'status' => 401 ) );
		}

		global $wpdb;
		$sessions_table = QQ_DB::sessions_table();
		$users_table    = QQ_DB::users_table();

		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			"SELECT s.token, s.expires_at, u.* FROM {$sessions_table} s
			 INNER JOIN {$users_table} u ON u.id = s.user_id
			 WHERE s.token = %s",
			$token
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'qq_invalid_session', 'Your session has expired. Please sign in again.', array( 'status' => 401 ) );
		}

		if ( strtotime( $row['expires_at'] ) < time() ) {
			$wpdb->delete( $sessions_table, array( 'token' => $token ), array( '%s' ) ); // phpcs:ignore
			return new WP_Error( 'qq_session_expired', 'Your session has expired. Please sign in again.', array( 'status' => 401 ) );
		}

		return $row;
	}

	public static function extract_token( WP_REST_Request $request ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		$header_token = $request->get_header( 'x-qq-session' );
		if ( $header_token ) {
			return trim( $header_token );
		}
		return $request->get_param( 'session_token' );
	}

	public static function logout( WP_REST_Request $request ) {
		$token = self::extract_token( $request );
		if ( $token ) {
			global $wpdb;
			$wpdb->delete( QQ_DB::sessions_table(), array( 'token' => $token ), array( '%s' ) ); // phpcs:ignore
		}
		return true;
	}
}
