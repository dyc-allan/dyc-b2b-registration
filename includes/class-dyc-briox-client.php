<?php
/**
 * Briox API client (Swedish API).
 *
 * Credentials come from DYC_Briox_Settings: wp-config.php constants (DYC_BRIOX_CLIENT_ID,
 * DYC_BRIOX_AUTH_TOKEN, DYC_BRIOX_ENV) when defined, otherwise WooCommerce → Settings → Briox.
 *
 * API quirks this class handles (verified against the test account, see BRIOX_INTEGRATION_PLAN.md):
 * - The access token goes in the Authorization header as the raw token (no "Bearer ").
 * - Tokens last 30 days. A refresh returns a NEW access AND refresh token and kills the old
 *   access token at once, so refreshes are serialized with a database lock.
 * - Errors are {"error":["<message>"],"error_code":[],"data":null}; only the message tells them
 *   apart, and "not found" is HTTP 400 "Customer missing!".
 * - The authentication token from wp-config.php can only be exchanged ONCE. After that the connection
 *   lives on refreshes alone, so a daily keep-alive refreshes well before expiry (DYC_Briox_Sync), and
 *   a broken chain is reported in WP-Admin (option dyc_briox_connection_error) until a new
 *   authentication token is put in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'DYC_Briox_Client' ) ) {

    class DYC_Briox_Client {

        const BASE_URL     = 'https://api-se.briox.services/v2';
        const TOKEN_OPTION = 'dyc_briox_tokens';
        const LOCK_NAME    = 'dyc_briox_token_refresh';
        const ERROR_OPTION = 'dyc_briox_connection_error';

        // Refresh this long before the access token expires (the daily keep-alive gets several chances)
        const REFRESH_MARGIN = 3 * DAY_IN_SECONDS;

        /**
         * Are the wp-config.php credentials present?
         */
        public static function is_configured() {
            $credentials = DYC_Briox_Settings::credentials();
            return '' !== $credentials['client_id'] && '' !== $credentials['auth_token'];
        }

        /**
         * 'test' or 'prod' (informational; both use the same API host)
         */
        public static function env() {
            return DYC_Briox_Settings::credentials()['env'];
        }

        /**
         * GET/POST/PUT/DELETE against the API.
         *
         * @param string     $method HTTP method.
         * @param string     $path   Path below /v2, e.g. '/customer/12'.
         * @param array|null $body   Request body (already wrapped, e.g. array( 'customer' => ... )).
         * @return array|WP_Error Decoded "data" member on success. On failure a WP_Error whose data has
         *                        'status' (HTTP code, 0 for transport errors) and 'retryable' (bool).
         */
        public function request( $method, $path, $body = null ) {
            $token = $this->get_access_token();
            if ( is_wp_error( $token ) ) {
                return $token;
            }

            $result = $this->raw_request( $method, $path, $body, $token );

            // Token rejected although we think it's valid (revoked or refreshed elsewhere): renew once and retry
            if ( is_wp_error( $result ) && 401 === $this->error_status( $result ) ) {
                $token = $this->get_access_token( true );
                if ( is_wp_error( $token ) ) {
                    return $token;
                }
                $result = $this->raw_request( $method, $path, $body, $token );
            }

            // Any successful call proves the connection works again
            if ( ! is_wp_error( $result ) && false !== get_option( self::ERROR_OPTION ) ) {
                delete_option( self::ERROR_OPTION );
            }

            return $result;
        }

        /**
         * Is this the API's "not found" error? (Briox returns HTTP 400, not 404)
         */
        public static function is_not_found( $error ) {
            return is_wp_error( $error ) && false !== stripos( $error->get_error_message(), 'missing' );
        }

        private function error_status( WP_Error $error ) {
            $data = $error->get_error_data();
            return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        }

        /**
         * One HTTP request, no token handling
         */
        private function raw_request( $method, $path, $body, $token ) {
            $args = array(
                'method'  => $method,
                'timeout' => 30,
                'headers' => array(
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ),
            );
            if ( null !== $token ) {
                $args['headers']['Authorization'] = $token;
            }
            if ( null !== $body ) {
                $args['body'] = wp_json_encode( $body );
            }

            $response = wp_remote_request( self::BASE_URL . $path, $args );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'dyc_briox_transport', $response->get_error_message(), array( 'status' => 0, 'retryable' => true ) );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $raw    = wp_remote_retrieve_body( $response );
            $json   = json_decode( $raw, true );

            if ( $status >= 200 && $status < 300 && is_array( $json ) && array_key_exists( 'data', $json ) ) {
                return is_array( $json['data'] ) ? $json['data'] : array();
            }

            $message = is_array( $json ) && ! empty( $json['error'] )
                ? implode( ' ', (array) $json['error'] )
                : sprintf( 'HTTP %d: %s', $status, substr( wp_strip_all_tags( (string) $raw ), 0, 200 ) );

            return new WP_Error(
                'dyc_briox_http_' . $status,
                $message,
                array(
                    'status'    => $status,
                    // Server-side or rate-limit problems are worth retrying; validation errors (400) are not
                    'retryable' => 429 === $status || $status >= 500 || 0 === $status,
                )
            );
        }

        /**
         * Hash of the configured credentials, to notice when wp-config.php changes (e.g. test -> prod)
         */
        private function credentials_hash() {
            $credentials = DYC_Briox_Settings::credentials();
            return md5( $credentials['client_id'] . '|' . $credentials['auth_token'] );
        }

        /**
         * Stored token set, read fresh from the database (another process may have refreshed it)
         */
        private function load_tokens() {
            wp_cache_delete( self::TOKEN_OPTION, 'options' );
            $tokens = get_option( self::TOKEN_OPTION );
            return is_array( $tokens ) ? $tokens : array();
        }

        private function save_tokens( array $data ) {
            $tokens = array(
                'access_token'     => (string) $data['access_token'],
                'refresh_token'    => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
                'expire_timestamp' => isset( $data['expire_timestamp'] ) ? (int) $data['expire_timestamp'] : time() + DAY_IN_SECONDS,
                'credentials'      => $this->credentials_hash(),
                'env'              => self::env(),
                'obtained'         => time(),
            );
            update_option( self::TOKEN_OPTION, $tokens, false );
            return $tokens;
        }

        /**
         * Do the stored tokens belong to the configured credentials?
         * Token sets saved before this field existed (manual tests) are trusted.
         */
        private function same_credentials( array $tokens ) {
            return ! isset( $tokens['credentials'] ) || $tokens['credentials'] === $this->credentials_hash();
        }

        private function token_usable( array $tokens ) {
            return ! empty( $tokens['access_token'] )
                && $this->same_credentials( $tokens )
                && isset( $tokens['expire_timestamp'] ) && (int) $tokens['expire_timestamp'] > time() + self::REFRESH_MARGIN;
        }

        /**
         * A valid access token, obtaining or refreshing one when needed.
         *
         * @param bool $force_renew Renew even if the stored token looks valid (after a 401).
         * @return string|WP_Error
         */
        public function get_access_token( $force_renew = false ) {
            if ( ! self::is_configured() ) {
                return new WP_Error( 'dyc_briox_not_configured', 'Briox-uppgifter saknas (WooCommerce → Inställningar → Briox).', array( 'status' => 0, 'retryable' => false ) );
            }

            $tokens = $this->load_tokens();
            if ( ! $force_renew && $this->token_usable( $tokens ) ) {
                return $tokens['access_token'];
            }

            // Only one process may refresh: a refresh invalidates the old token immediately
            if ( ! $this->acquire_lock() ) {
                return new WP_Error( 'dyc_briox_lock', 'Could not get the Briox token lock.', array( 'status' => 0, 'retryable' => true ) );
            }

            try {
                // Another process may have renewed the token while we waited for the lock
                $fresh = $this->load_tokens();
                if ( $this->token_usable( $fresh ) && ( ! $force_renew || $fresh['access_token'] !== ( $tokens['access_token'] ?? '' ) ) ) {
                    return $fresh['access_token'];
                }

                $renewed = null;

                // Prefer a refresh when we have tokens for the same credentials
                if ( ! empty( $fresh['refresh_token'] ) && $this->same_credentials( $fresh ) ) {
                    $renewed = $this->raw_request(
                        'POST',
                        '/tokenrefresh?' . http_build_query( array( 'refreshtoken' => $fresh['refresh_token'], 'token' => $fresh['access_token'] ) ),
                        null,
                        null
                    );
                }

                // No tokens yet, credentials changed, or refresh failed: exchange the authentication token
                if ( null === $renewed || is_wp_error( $renewed ) ) {
                    $renewed = $this->raw_request(
                        'POST',
                        '/token?' . http_build_query( array( 'clientid' => DYC_Briox_Settings::credentials()['client_id'], 'token' => DYC_Briox_Settings::credentials()['auth_token'] ) ),
                        null,
                        null
                    );
                }

                if ( is_wp_error( $renewed ) ) {
                    // Refresh and exchange both failed: the connection needs a new authentication token
                    update_option(
                        self::ERROR_OPTION,
                        array(
                            'message'     => $renewed->get_error_message(),
                            'credentials' => $this->credentials_hash(),
                            'time'        => time(),
                        ),
                        false
                    );
                    return $renewed;
                }
                if ( empty( $renewed['access_token'] ) ) {
                    return new WP_Error( 'dyc_briox_token', 'Briox returned no access token.', array( 'status' => 0, 'retryable' => true ) );
                }

                delete_option( self::ERROR_OPTION );
                return $this->save_tokens( $renewed )['access_token'];
            } finally {
                $this->release_lock();
            }
        }

        /**
         * The stored connection error, if it still applies to the configured credentials
         * (a new authentication token in wp-config.php clears it on the next attempt).
         *
         * @return array|null { message, time }
         */
        public function connection_error() {
            $error = get_option( self::ERROR_OPTION );
            if ( ! is_array( $error ) || ! self::is_configured() ) {
                return null;
            }
            return ( $error['credentials'] ?? '' ) === $this->credentials_hash() ? $error : null;
        }

        /**
         * Expiry time of the stored access token (0 if none)
         */
        public function token_expiry() {
            $tokens = $this->load_tokens();
            return isset( $tokens['expire_timestamp'] ) ? (int) $tokens['expire_timestamp'] : 0;
        }

        private function acquire_lock() {
            global $wpdb;
            return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, 15 ) );
        }

        private function release_lock() {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
        }
    }
}
