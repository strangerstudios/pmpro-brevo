<?php
/**
 * Brevo API wrapper.
 *
 * Uses the Brevo v3 REST API (api.brevo.com/v3).
 * Authentication is an API key sent in the `api-key` header.
 *
 * @since TBD
 */

defined( 'ABSPATH' ) || exit;

class PMPro_Brevo_API {

	/**
	 * Singleton instance.
	 *
	 * @var PMPro_Brevo_API|null
	 */
	private static $instance = null;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://api.brevo.com/v3';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Whether the API is connected and ready.
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Cached lists.
	 *
	 * @var array|null
	 */
	private $lists_cache = null;

	/**
	 * Get singleton instance.
	 *
	 * @since TBD
	 *
	 * @return PMPro_Brevo_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Loads the stored API key.
	 */
	private function __construct() {
		$options = get_option( 'pmprobrevo_options', array() );
		if ( ! empty( $options['api_key'] ) ) {
			$this->api_key   = $options['api_key'];
			$this->connected = true;
		}
	}

	/**
	 * Check if the API is connected (key is set).
	 *
	 * @since TBD
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->connected;
	}

	/**
	 * Replace the API key used for requests, e.g. right after a new key is saved.
	 *
	 * @since TBD
	 *
	 * @param string $api_key API key.
	 */
	public function set_api_key( $api_key ) {
		$this->api_key     = (string) $api_key;
		$this->connected   = ! empty( $api_key );
		$this->lists_cache = null;
	}

	/**
	 * Make an API request.
	 *
	 * @since TBD
	 *
	 * @param string $endpoint Relative endpoint (e.g. '/contacts').
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body (for POST/PUT/PATCH).
	 * @param array  $query    Query parameters.
	 * @return array|WP_Error Decoded response body or WP_Error on failure.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $query = array() ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'not_connected', __( 'Brevo API key not configured.', 'pmpro-brevo' ) );
		}

		$url = $this->api_url . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'api-key'      => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'PMPro-Brevo/' . PMPROBREVO_VERSION,
			),
			'timeout' => 15,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			pmprobrevo_debug_log( "API error ({$method} {$endpoint}): " . $response->get_error_message() );
			$response->add_data( array( 'status' => 0 ) );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 204 No Content is a success response (e.g. updating an existing contact).
		if ( 204 === $code ) {
			return array();
		}

		if ( 429 === $code ) {
			// Seconds until the rate limit window resets.
			$retry_after = (int) wp_remote_retrieve_header( $response, 'x-sib-ratelimit-reset' );
			pmprobrevo_debug_log( "Rate limited ({$method} {$endpoint}). Resets in {$retry_after} seconds." );
			return new WP_Error( 'rate_limited', __( 'Brevo API rate limit reached. Please try again later.', 'pmpro-brevo' ), array( 'status' => 429, 'retry_after' => $retry_after ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = ! empty( $data['message'] ) ? $data['message'] : "HTTP {$code}";
			if ( ! empty( $data['code'] ) ) {
				$error_message = $data['code'] . ': ' . $error_message;
			}
			pmprobrevo_debug_log( "API error ({$method} {$endpoint}): {$error_message}" );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'response' => $data ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get the HTTP status code from an error returned by request().
	 *
	 * @since TBD
	 *
	 * @param WP_Error $error Error returned by request().
	 * @return int HTTP status, or 0 for a transport error (timeout, DNS, etc.).
	 */
	public static function get_error_status( $error ) {
		$data = $error->get_error_data();
		return ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 0;
	}

	/**
	 * Check whether a request error is temporary and worth retrying.
	 *
	 * Transport errors, 408, 425, 429 and 5xx responses are retryable.
	 *
	 * @since TBD
	 *
	 * @param WP_Error $error Error returned by request().
	 * @return bool
	 */
	public static function is_retryable_error( $error ) {
		if ( ! is_wp_error( $error ) || 'not_connected' === $error->get_error_code() ) {
			return false;
		}

		$status = self::get_error_status( $error );
		return 0 === $status || in_array( $status, array( 408, 425, 429 ), true ) || $status >= 500;
	}

	// ------------------------------------------------------------------
	// Account
	// ------------------------------------------------------------------

	/**
	 * Get the Brevo account for the current API key.
	 *
	 * Used to test the connection and to tell whether a new API key belongs to a different account.
	 *
	 * @since TBD
	 *
	 * @return array|WP_Error Account data (email, companyName, organization_id, ...).
	 */
	public function get_account() {
		return $this->request( '/account' );
	}

	/**
	 * Get a stable identifier for a Brevo account.
	 *
	 * @since TBD
	 *
	 * @param array $account Account data from get_account().
	 * @return string Account identifier, or an empty string if none is available.
	 */
	public static function get_account_fingerprint( $account ) {
		if ( ! is_array( $account ) ) {
			return '';
		}
		if ( ! empty( $account['organization_id'] ) ) {
			return 'org:' . $account['organization_id'];
		}
		if ( ! empty( $account['email'] ) ) {
			return 'email:' . strtolower( $account['email'] );
		}
		return '';
	}

	// ------------------------------------------------------------------
	// Lists
	// ------------------------------------------------------------------

	/**
	 * Get all lists from Brevo with transient caching.
	 *
	 * @since TBD
	 *
	 * @param bool $force_refresh Skip the cache.
	 * @return array
	 */
	public function get_lists( $force_refresh = false ) {
		if ( null !== $this->lists_cache && ! $force_refresh ) {
			return $this->lists_cache;
		}

		$cached = get_transient( 'pmprobrevo_all_lists' );
		if ( false !== $cached && ! $force_refresh ) {
			$this->lists_cache = $cached;
			return $cached;
		}

		$all_lists = array();
		$limit     = 50;
		$offset    = 0;

		while ( true ) {
			$result = $this->request( '/contacts/lists', 'GET', array(), array(
				'limit'  => $limit,
				'offset' => $offset,
				'sort'   => 'asc',
			) );

			if ( is_wp_error( $result ) ) {
				return array();
			}

			if ( ! empty( $result['lists'] ) ) {
				foreach ( $result['lists'] as $list ) {
					$all_lists[] = array(
						'id'                => (int) $list['id'],
						'name'              => $list['name'],
						'total_subscribers' => ! empty( $list['totalSubscribers'] ) ? (int) $list['totalSubscribers'] : 0,
					);
				}
			}

			$offset += $limit;
			$count   = isset( $result['count'] ) ? (int) $result['count'] : 0;
			if ( empty( $result['lists'] ) || $offset >= $count ) {
				break;
			}

			// Safety valve.
			if ( $offset > 5000 ) {
				break;
			}
		}

		usort( $all_lists, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$this->lists_cache = $all_lists;
		set_transient( 'pmprobrevo_all_lists', $all_lists, 12 * HOUR_IN_SECONDS );

		return $all_lists;
	}

	// ------------------------------------------------------------------
	// Contacts
	// ------------------------------------------------------------------

	/**
	 * Get a contact by email address.
	 *
	 * @since TBD
	 *
	 * @param string $email Contact email address.
	 * @return array|WP_Error Contact data (id, email, emailBlacklisted, listIds, ...).
	 */
	public function get_contact( $email ) {
		return $this->request( '/contacts/' . rawurlencode( $email ), 'GET', array(), array( 'identifierType' => 'email_id' ) );
	}

	/**
	 * Get a contact by its Brevo contact ID.
	 *
	 * @since TBD
	 *
	 * @param int $contact_id Brevo contact ID.
	 * @return array|WP_Error Contact data. WP_Error with status 404 if the contact no longer exists.
	 */
	public function get_contact_by_id( $contact_id ) {
		return $this->request( '/contacts/' . intval( $contact_id ), 'GET', array(), array( 'identifierType' => 'contact_id' ) );
	}

	/**
	 * Create or update a contact (upsert).
	 *
	 * POST /contacts with updateEnabled is a non-destructive upsert in Brevo —
	 * listIds are added, not replaced, and omitted attributes are untouched.
	 * Returns 201 with the new ID on create, or 204 with no body on update.
	 *
	 * @since TBD
	 *
	 * @param array $contact_data Contact data.
	 * @return array|WP_Error Response data.
	 */
	public function upsert_contact( $contact_data ) {
		$contact_data['updateEnabled'] = true;
		return $this->request( '/contacts', 'POST', $contact_data );
	}

	/**
	 * Update an existing contact by its Brevo contact ID.
	 *
	 * Used instead of the email upsert when we already know the contact,
	 * so an email address change updates the contact rather than creating a new one.
	 * A new email is passed as the EMAIL attribute. Returns 204 with no body on success.
	 *
	 * @since TBD
	 *
	 * @param int   $contact_id   Brevo contact ID.
	 * @param array $contact_data Contact data (attributes, listIds, unlinkListIds, emailBlacklisted).
	 * @return array|WP_Error Response data.
	 */
	public function update_contact( $contact_id, $contact_data ) {
		return $this->request( '/contacts/' . intval( $contact_id ), 'PUT', $contact_data, array( 'identifierType' => 'contact_id' ) );
	}

	/**
	 * Update an existing contact by email address.
	 *
	 * Used to remove lists from a contact when its Brevo ID isn't known.
	 * Returns a 404 WP_Error if no contact has this email.
	 *
	 * @since TBD
	 *
	 * @param string $email        Contact email address.
	 * @param array  $contact_data Contact data (attributes, listIds, unlinkListIds).
	 * @return array|WP_Error Response data.
	 */
	public function update_contact_by_email( $email, $contact_data ) {
		return $this->request( '/contacts/' . rawurlencode( $email ), 'PUT', $contact_data, array( 'identifierType' => 'email_id' ) );
	}
}
