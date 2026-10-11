<?php
/**
 * Briox settings: where the credentials come from, and the payment terms for new customers.
 *
 * Credentials are read from wp-config.php constants when defined (they win), otherwise from the
 * WooCommerce → Settings → Briox tab (option dyc_briox_settings). Stored settings are bound to the
 * site address they were saved on: a copy of the database on another site (dev/staging) must not
 * use the live Briox account, because every token refresh there would kill production's token.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'DYC_Briox_Settings' ) ) {

    class DYC_Briox_Settings {

        const OPTION        = 'dyc_briox_settings';
        const TERMS_CACHE   = 'dyc_briox_payment_terms';
        const DEFAULT_TERMS = '15';

        /**
         * Stored settings with defaults
         */
        public static function stored() {
            $settings = get_option( self::OPTION );
            return wp_parse_args(
                is_array( $settings ) ? $settings : array(),
                array(
                    'client_id'     => '',
                    'auth_token'    => '',
                    'env'           => 'test',
                    'payment_terms' => self::DEFAULT_TERMS,
                    'site_url'      => '',
                )
            );
        }

        /**
         * Are the credentials defined in wp-config.php?
         */
        public static function defined_in_config() {
            return defined( 'DYC_BRIOX_CLIENT_ID' ) && defined( 'DYC_BRIOX_AUTH_TOKEN' );
        }

        /**
         * Normalized site address used to bind stored credentials to this site
         */
        public static function current_site() {
            return untrailingslashit( preg_replace( '#^https?://#i', '', strtolower( home_url() ) ) );
        }

        /**
         * Stored credentials exist but were saved on another site address
         */
        public static function site_mismatch() {
            if ( self::defined_in_config() ) {
                return false;
            }
            $stored = self::stored();
            return '' !== $stored['auth_token'] && '' !== $stored['site_url'] && $stored['site_url'] !== self::current_site();
        }

        /**
         * The credentials to use.
         *
         * @return array { client_id, auth_token, env, source: 'wp-config'|'settings'|'' }
         */
        public static function credentials() {
            if ( self::defined_in_config() ) {
                return array(
                    'client_id'  => trim( (string) DYC_BRIOX_CLIENT_ID ),
                    'auth_token' => trim( (string) DYC_BRIOX_AUTH_TOKEN ),
                    'env'        => defined( 'DYC_BRIOX_ENV' ) ? (string) DYC_BRIOX_ENV : 'test',
                    'source'     => 'wp-config',
                );
            }

            $stored = self::stored();
            if ( '' === $stored['client_id'] || '' === $stored['auth_token'] || self::site_mismatch() ) {
                return array( 'client_id' => '', 'auth_token' => '', 'env' => $stored['env'], 'source' => '' );
            }

            return array(
                'client_id'  => $stored['client_id'],
                'auth_token' => $stored['auth_token'],
                'env'        => $stored['env'],
                'source'     => 'settings',
            );
        }

        /**
         * Payment terms key for new Briox customers
         */
        public static function payment_terms() {
            $terms = (string) self::stored()['payment_terms'];
            return '' !== $terms ? $terms : self::DEFAULT_TERMS;
        }

        /**
         * Payment terms available in the Briox account, as key => label (cached 12 h).
         * Falls back to the current setting when Briox can't be reached.
         */
        public static function payment_term_options() {
            $cached = get_transient( self::TERMS_CACHE );
            if ( is_array( $cached ) && $cached ) {
                return $cached;
            }

            $options = array();
            if ( DYC_Briox_Client::is_configured() ) {
                $data = ( new DYC_Briox_Client() )->request( 'GET', '/payment/term' );
                if ( ! is_wp_error( $data ) ) {
                    foreach ( (array) ( $data['paymentterms'] ?? array() ) as $term ) {
                        if ( ! isset( $term['id'] ) ) {
                            continue;
                        }
                        $label = $term['language']['sv_SE'] ?? $term['language']['en_GB'] ?? $term['id'];
                        $options[ (string) $term['id'] ] = $label;
                    }
                    if ( $options ) {
                        set_transient( self::TERMS_CACHE, $options, 12 * HOUR_IN_SECONDS );
                    }
                }
            }

            if ( ! $options ) {
                $current             = self::payment_terms();
                $options[ $current ] = ctype_digit( $current ) ? $current . ' dagar' : $current;
            }
            return $options;
        }

        /**
         * URL of the settings tab
         */
        public static function settings_url() {
            return admin_url( 'admin.php?page=wc-settings&tab=dyc_briox' );
        }
    }
}
