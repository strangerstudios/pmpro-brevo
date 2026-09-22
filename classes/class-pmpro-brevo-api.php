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
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 204 No Content is a success response (e.g. updating an existing contact).
		if ( 204 === $code ) {
			return array();
		}

		if ( 429 === $code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			pmprobrevo_debug_log( "Rate limited ({$method} {$endpoint}). Retry-After: {$retry_after}" );
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

	/**
	 * Remove a contact from a list.
	 *
	 * @since TBD
	 *
	 * @param string $email   Contact email address.
	 * @param int    $list_id List ID.
	 * @return array|WP_Error
	 */
	public function remove_contact_from_list( $email, $list_id ) {
		return $this->request( '/contacts/lists/' . intval( $list_id ) . '/contacts/remove', 'POST', array( 'emails' => array( $email ) ) );
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
}
