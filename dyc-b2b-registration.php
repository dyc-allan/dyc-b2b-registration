<?php
/**
 * Plugin Name: DYC B2B Registration
 * Plugin URI: https://dyc.local
 * Description: Custom B2B registration features for WooCommerce with Swedish Organisationsnummer (Luhn + VIES validation), corporate email checks, and Kadence Advanced Form integration.
 * Version: 1.2.0
 * Author: DYC Development
 * Author URI: https://dyc.local
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: dyc-b2b-registration
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! defined( 'DYC_B2B_REGISTRATION_FILE' ) ) {
    define( 'DYC_B2B_REGISTRATION_FILE', __FILE__ );
}

if ( ! defined( 'DYC_B2B_REGISTRATION_PLUGIN_URL' ) ) {
    define( 'DYC_B2B_REGISTRATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'DYC_B2B_REGISTRATION_PLUGIN_PATH' ) ) {
    define( 'DYC_B2B_REGISTRATION_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DYC_B2B_REGISTRATION_VERSION' ) ) {
    define( 'DYC_B2B_REGISTRATION_VERSION', '1.2.0' );
}

if ( ! class_exists( 'DYC_B2B_Registration' ) ) {

    class DYC_B2B_Registration {

        private static $instance = null;
        private static $kadence_error = '';

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            // Role initialization
            add_action( 'init', array( $this, 'init' ) );
            register_activation_hook( DYC_B2B_REGISTRATION_FILE, array( $this, 'activate' ) );
            register_deactivation_hook( DYC_B2B_REGISTRATION_FILE, array( $this, 'deactivate' ) );

            // Role-based field filtering (only B2B / Admin roles see company & orgnr in standard checkout/addresses)
            add_filter( 'woocommerce_billing_fields', array( $this, 'filter_billing_fields_by_role' ), 20 );
            add_filter( 'woocommerce_shipping_fields', array( $this, 'filter_shipping_fields_by_role' ), 20 );
            add_filter( 'woocommerce_admin_billing_fields', array( $this, 'add_admin_billing_fields' ) );

            // Standalone B2B registration shortcodes
            add_shortcode( 'dyc_b2b_registration', array( $this, 'render_b2b_registration_form' ) );
            add_shortcode( 'dyc_b2b_registration_form', array( $this, 'render_b2b_registration_form' ) );

            // Handle B2B form POST submission (shortcode fallback)
            add_action( 'template_redirect', array( $this, 'handle_b2b_registration_submit' ) );

            // AJAX lookup for Swedish Organisationsnummer via VIES
            add_action( 'wp_ajax_dyc_lookup_orgnr', array( $this, 'ajax_lookup_orgnr' ) );
            add_action( 'wp_ajax_nopriv_dyc_lookup_orgnr', array( $this, 'ajax_lookup_orgnr' ) );

            // Kadence Advanced Form Integration hooks
            add_filter( 'kadence_blocks_advanced_form_submission_reject', array( $this, 'kadence_validate_advanced_form' ), 10, 4 );
            add_filter( 'kadence_blocks_advanced_form_submission_reject_message', array( $this, 'kadence_advanced_form_reject_message' ), 10, 4 );
            add_action( 'kadence_blocks_advanced_form_submission', array( $this, 'kadence_process_advanced_form' ), 10, 3 );
            add_filter( 'kadence_blocks_advanced_form_submission_success', array( $this, 'kadence_submission_success' ), 10, 5 );
            add_filter( 'kadence_blocks_advanced_form_submission_messages', array( $this, 'kadence_submission_messages' ), 10, 5 );
        }

        public function init() {
            if ( ! get_role( 'business' ) ) {
                $this->activate();
            }
        }

        public function activate() {
            if ( ! get_role( 'business' ) ) {
                $customer_role = get_role( 'customer' );
                $capabilities  = $customer_role ? $customer_role->capabilities : array( 'read' => true );

                add_role( 
                    'business', 
                    __( 'Business', 'dyc-b2b-registration' ), 
                    $capabilities 
                );
            }
        }

        public function deactivate() {
            // Keep role on deactivation so existing users don't break.
        }

        /**
         * Check if current user has business or admin capabilities
         */
        public function current_user_can_b2b() {
            if ( ! is_user_logged_in() ) {
                return false;
            }
            $user = wp_get_current_user();
            $allowed_roles = array( 'business', 'administrator', 'shop_manager' );
            return (bool) array_intersect( $allowed_roles, (array) $user->roles );
        }

        /**
         * Hide company, org.nr, and invoice email fields for retail customers & guests on standard checkout/my-account
         */
        public function filter_billing_fields_by_role( $fields ) {
            if ( is_admin() && ! wp_doing_ajax() ) {
                return $fields;
            }

            if ( ! $this->current_user_can_b2b() ) {
                if ( isset( $fields['billing_company'] ) ) {
                    unset( $fields['billing_company'] );
                }
                if ( isset( $fields['billing_orgnr'] ) ) {
                    unset( $fields['billing_orgnr'] );
                }
                if ( isset( $fields['billing_invoice_email'] ) ) {
                    unset( $fields['billing_invoice_email'] );
                }
            }

            return $fields;
        }

        /**
         * Hide shipping company field for retail customers & guests
         */
        public function filter_shipping_fields_by_role( $fields ) {
            if ( is_admin() && ! wp_doing_ajax() ) {
                return $fields;
            }

            if ( ! $this->current_user_can_b2b() ) {
                if ( isset( $fields['shipping_company'] ) ) {
                    unset( $fields['shipping_company'] );
                }
            }

            return $fields;
        }

        /**
         * Make sure billing_orgnr and billing_invoice_email appear in WP-Admin order & user edit screens
         */
        public function add_admin_billing_fields( $fields ) {
            if ( ! isset( $fields['orgnr'] ) ) {
                $fields['orgnr'] = array(
                    'label' => __( 'Organisationsnummer', 'dyc-b2b-registration' ),
                    'show'  => true,
                );
            }
            if ( ! isset( $fields['invoice_email'] ) ) {
                $fields['invoice_email'] = array(
                    'label' => __( 'Faktura-e-post', 'dyc-b2b-registration' ),
                    'show'  => true,
                );
            }
            return $fields;
        }

        /**
         * Validate Swedish Organisationsnummer with Luhn Algorithm (Modulus 10)
         */
        public static function is_valid_luhn( $number ) {
            $clean = preg_replace( '/\D/', '', $number );
            if ( strlen( $clean ) === 12 ) {
                $clean = substr( $clean, 2 ); // Remove century prefix if provided
            }
            if ( strlen( $clean ) !== 10 ) {
                return false;
            }

            $sum = 0;
            for ( $i = 0; $i < 10; $i++ ) {
                $digit = (int) $clean[ $i ];
                if ( $i % 2 === 0 ) {
                    $digit *= 2;
                    if ( $digit > 9 ) {
                        $digit -= 9;
                    }
                }
                $sum += $digit;
            }
            return ( $sum % 10 === 0 );
        }

        /**
         * Query EU VIES API for Swedish VAT Number (SE + 10-digit orgnr + 01)
         */
        public static function query_vies_vat( $orgnr_10_digits ) {
            $vat_number = $orgnr_10_digits . '01';
            $url = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/SE/vat/' . rawurlencode( $vat_number );

            if ( function_exists( 'curl_init' ) ) {
                $ch = curl_init( $url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
                curl_setopt( $ch, CURLOPT_USERAGENT, 'WordPress/DYC-B2B-Registration' );
                curl_setopt( $ch, CURLOPT_TIMEOUT, 6 );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
                $body = curl_exec( $ch );
                $err  = curl_error( $ch );
                curl_close( $ch );

                if ( ! $err && ! empty( $body ) ) {
                    $data = json_decode( $body, true );
                    if ( is_array( $data ) && isset( $data['isValid'] ) ) {
                        return $data;
                    }
                }
            }

            return null; // Return null if service is unreachable
        }

        /**
         * Check if email domain is a generic/consumer provider
         */
        public static function is_public_email_domain( $email ) {
            $public_domains = array(
                'gmail.com', 'googlemail.com',
                'hotmail.com', 'hotmail.se',
                'live.com', 'live.se',
                'outlook.com',
                'yahoo.com', 'yahoo.se',
                'icloud.com', 'me.com',
                'telia.com', 'spray.se', 'bredband.net', 'comhem.se',
                'gmx.com', 'mail.com', 'protonmail.com', 'proton.me',
                'yandex.com', 'zoho.com'
            );

            $parts  = explode( '@', strtolower( trim( $email ) ) );
            $domain = end( $parts );

            return in_array( $domain, $public_domains, true );
        }

        /**
         * Strip an org.nr to its 10 digits (drops separators and an optional century prefix)
         */
        public static function clean_orgnr( $orgnr ) {
            $clean = preg_replace( '/\D/', '', $orgnr );
            if ( strlen( $clean ) === 12 ) {
                $clean = substr( $clean, 2 );
            }
            return $clean;
        }

        /**
         * Build a unique username from the email's local part.
         * Corporate addresses often share local parts (info@, order@), so append -2, -3 ... until free.
         */
        public static function generate_unique_username( $email ) {
            $base = sanitize_user( current( explode( '@', $email ) ), true );
            if ( '' === $base ) {
                $base = 'foretag';
            }

            $username = $base;
            $suffix   = 2;
            while ( username_exists( $username ) ) {
                $username = $base . '-' . $suffix;
                $suffix++;
            }

            return $username;
        }

        /**
         * AJAX endpoint to lookup and validate Organisationsnummer
         */
        public function ajax_lookup_orgnr() {
            check_ajax_referer( 'dyc_b2b_nonce', 'nonce' );

            $orgnr = isset( $_POST['orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['orgnr'] ) ) : '';
            $clean = preg_replace( '/\D/', '', $orgnr );
            if ( strlen( $clean ) === 12 ) {
                $clean = substr( $clean, 2 );
            }

            if ( strlen( $clean ) !== 10 ) {
                wp_send_json_error( array( 'message' => __( 'Organisationsnumret måste bestå av 10 siffror.', 'dyc-b2b-registration' ) ) );
            }

            if ( ! self::is_valid_luhn( $clean ) ) {
                wp_send_json_error( array( 'message' => __( 'Ogiltigt organisationsnummer (kontrollsiffran stämmer inte).', 'dyc-b2b-registration' ) ) );
            }

            // Check VIES API
            $vies = self::query_vies_vat( $clean );
            if ( $vies !== null ) {
                if ( empty( $vies['isValid'] ) ) {
                    wp_send_json_error( array( 'message' => __( 'Organisationsnumret hittades inte eller är inte aktivt för moms hos Skatteverket.', 'dyc-b2b-registration' ) ) );
                }

                $name    = ( ! empty( $vies['name'] ) && $vies['name'] !== '---' ) ? $vies['name'] : '';
                $address = ( ! empty( $vies['address'] ) && $vies['address'] !== '---' ) ? $vies['address'] : '';

                wp_send_json_success( array(
                    'message'      => sprintf( __( 'Verifierat: %s', 'dyc-b2b-registration' ), $name ?: $clean ),
                    'company_name' => $name,
                    'address'      => $address,
                    'vat_number'   => 'SE' . $clean . '01',
                    'formatted'    => substr( $clean, 0, 6 ) . '-' . substr( $clean, 6 ),
                ) );
            }

            // Fallback if VIES is temporarily down
            wp_send_json_success( array(
                'message'      => __( 'Giltigt format på organisationsnummer (VIES kunde inte nås tillfälligt).', 'dyc-b2b-registration' ),
                'company_name' => '',
                'address'      => '',
                'vat_number'   => 'SE' . $clean . '01',
                'formatted'    => substr( $clean, 0, 6 ) . '-' . substr( $clean, 6 ),
            ) );
        }

        /**
         * Helper: Extract fields by inputName or label from Kadence processed fields
         */
        public function kadence_extract_fields( $processed_fields ) {
            $data = array();
            if ( is_array( $processed_fields ) ) {
                foreach ( $processed_fields as $field ) {
                    $name = ! empty( $field['name'] ) ? $field['name'] : ( ! empty( $field['label'] ) ? $field['label'] : '' );
                    if ( $name && isset( $field['value'] ) ) {
                        $data[ $name ] = $field['value'];
                    }
                }
            }
            return $data;
        }

        /**
         * Helper: Read a sanitized Kadence field value, falling back to the raw POST
         */
        private function kadence_get( $data, $key ) {
            if ( ! empty( $data[ $key ] ) ) {
                return sanitize_text_field( $data[ $key ] );
            }
            if ( ! empty( $_POST[ $key ] ) ) {
                return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
            return '';
        }

        /**
         * Helper: Read a password straight from POST.
         * Kadence runs sanitize_text_field() on password fields, which would silently alter passwords containing e.g. "<" or "%20".
         */
        private function kadence_get_password( $key ) {
            return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
        }

        /**
         * Helper: Is this Kadence submission our B2B registration form?
         */
        private function is_kadence_b2b_form( $data ) {
            return ! empty( $data['billing_orgnr'] ) || ! empty( $_POST['billing_orgnr'] );
        }

        /**
         * Kadence Advanced Form: Validate Swedish Org.nr, corporate email, required fields & credentials.
         * Everything that could make account creation fail is checked here, so the submission action can rely on it.
         */
        public function kadence_validate_advanced_form( $reject, $form_args, $processed_fields, $post_id ) {
            $data = $this->kadence_extract_fields( $processed_fields );

            // Only intercept forms containing billing_orgnr
            if ( ! $this->is_kadence_b2b_form( $data ) ) {
                return $reject;
            }

            $orgnr      = $this->kadence_get( $data, 'billing_orgnr' );
            $company    = $this->kadence_get( $data, 'billing_company' );
            $first_name = $this->kadence_get( $data, 'billing_first_name' );
            $last_name  = $this->kadence_get( $data, 'billing_last_name' );
            $email      = sanitize_email( $this->kadence_get( $data, 'billing_email' ) );
            $inv_email  = $this->kadence_get( $data, 'billing_invoice_email' );
            $address_1  = $this->kadence_get( $data, 'billing_address_1' );
            $postcode   = $this->kadence_get( $data, 'billing_postcode' );
            $city       = $this->kadence_get( $data, 'billing_city' );
            $password   = $this->kadence_get_password( 'password' );
            $password_c = $this->kadence_get_password( 'password_confirm' );

            // 1. Required fields
            if ( empty( $company ) ) {
                self::$kadence_error = __( 'Företagsnamn är obligatoriskt.', 'dyc-b2b-registration' );
                return true;
            }
            if ( empty( $first_name ) || empty( $last_name ) ) {
                self::$kadence_error = __( 'Kontaktperson (för- och efternamn) är obligatoriskt.', 'dyc-b2b-registration' );
                return true;
            }
            if ( empty( $address_1 ) || empty( $postcode ) || empty( $city ) ) {
                self::$kadence_error = __( 'Faktureringsadress (gatuadress, postnummer och ort) är obligatorisk.', 'dyc-b2b-registration' );
                return true;
            }

            // 2. Validate Organisationsnummer (Luhn & VIES)
            $clean_org = self::clean_orgnr( $orgnr );
            if ( strlen( $clean_org ) !== 10 || ! self::is_valid_luhn( $clean_org ) ) {
                self::$kadence_error = __( 'Ogiltigt organisationsnummer. Kontrollera att det är ett korrekt 10-siffrigt nummer.', 'dyc-b2b-registration' );
                return true;
            }

            $vies = self::query_vies_vat( $clean_org );
            if ( $vies !== null && empty( $vies['isValid'] ) ) {
                self::$kadence_error = __( 'Organisationsnumret kunde inte verifieras som ett aktivt momsregistrerat företag hos Skatteverket/VIES.', 'dyc-b2b-registration' );
                return true;
            }

            // 3. Validate Corporate Email
            if ( empty( $email ) || ! is_email( $email ) ) {
                self::$kadence_error = __( 'Vänligen ange en giltig e-postadress.', 'dyc-b2b-registration' );
                return true;
            }
            if ( email_exists( $email ) ) {
                self::$kadence_error = __( 'En användare med denna e-postadress finns redan registrerad.', 'dyc-b2b-registration' );
                return true;
            }
            if ( self::is_public_email_domain( $email ) ) {
                self::$kadence_error = __( 'Vänligen ange en företags-e-postadress. Privata e-posttjänster (Gmail, Hotmail, etc.) tillåts inte för B2B-konton.', 'dyc-b2b-registration' );
                return true;
            }

            // 4. Validate Invoice Email if provided
            if ( ! empty( $inv_email ) && ! is_email( $inv_email ) ) {
                self::$kadence_error = __( 'Vänligen ange en giltig faktura-e-postadress.', 'dyc-b2b-registration' );
                return true;
            }

            // 5. Validate Password if provided (otherwise one is generated and WooCommerce emails a set-password link)
            if ( '' !== $password ) {
                if ( strlen( $password ) < 8 ) {
                    self::$kadence_error = __( 'Lösenordet måste bestå av minst 8 tecken.', 'dyc-b2b-registration' );
                    return true;
                }
                if ( isset( $_POST['password_confirm'] ) && $password !== $password_c ) {
                    self::$kadence_error = __( 'Lösenorden matchar inte.', 'dyc-b2b-registration' );
                    return true;
                }
            }

            return false;
        }

        /**
         * Kadence Advanced Form: Return rejection message if validation failed
         */
        public function kadence_advanced_form_reject_message( $message, $form_args, $processed_fields, $post_id ) {
            if ( ! empty( self::$kadence_error ) ) {
                return self::$kadence_error;
            }
            return $message;
        }

        /**
         * Kadence Advanced Form: Create WooCommerce customer and assign B2B metadata
         */
        public function kadence_process_advanced_form( $form_args, $processed_fields, $post_id ) {
            $data = $this->kadence_extract_fields( $processed_fields );

            if ( ! $this->is_kadence_b2b_form( $data ) ) {
                return;
            }

            $get = function( $key ) use ( $data ) {
                return $this->kadence_get( $data, $key );
            };

            $orgnr       = $get( 'billing_orgnr' );
            $company     = $get( 'billing_company' );
            $first_name  = $get( 'billing_first_name' );
            $last_name   = $get( 'billing_last_name' );
            $email       = sanitize_email( $get( 'billing_email' ) );
            $inv_email   = sanitize_email( $get( 'billing_invoice_email' ) );
            $phone       = $get( 'billing_phone' );
            $address_1   = $get( 'billing_address_1' );
            $address_2   = $get( 'billing_address_2' );
            $postcode    = $get( 'billing_postcode' );
            $city        = $get( 'billing_city' );
            $country     = $get( 'billing_country' ) ?: 'SE';
            $password    = $this->kadence_get_password( 'password' );

            // Format org.nr (already validated in kadence_validate_advanced_form)
            $clean_org       = self::clean_orgnr( $orgnr );
            $formatted_orgnr = substr( $clean_org, 0, 6 ) . '-' . substr( $clean_org, 6 );

            // Create WooCommerce customer. An empty password lets WooCommerce generate one and email a set-password link.
            $username    = self::generate_unique_username( $email );
            $customer_id = wc_create_new_customer( $email, $username, $password );

            if ( is_wp_error( $customer_id ) ) {
                // Picked up by kadence_submission_success() so the form reports failure instead of success
                self::$kadence_error = $customer_id->get_error_message();
                return;
            }

            // Assign 'business' role
            $user = new WP_User( $customer_id );
            $user->set_role( 'business' );

            // Update user metadata
            update_user_meta( $customer_id, 'first_name', $first_name );
            update_user_meta( $customer_id, 'last_name', $last_name );
            update_user_meta( $customer_id, 'billing_first_name', $first_name );
            update_user_meta( $customer_id, 'billing_last_name', $last_name );
            update_user_meta( $customer_id, 'billing_company', $company );
            update_user_meta( $customer_id, 'billing_orgnr', $formatted_orgnr );
            update_user_meta( $customer_id, 'billing_email', $email );
            if ( $inv_email ) {
                update_user_meta( $customer_id, 'billing_invoice_email', $inv_email );
            }
            update_user_meta( $customer_id, 'billing_phone', $phone );
            update_user_meta( $customer_id, 'billing_address_1', $address_1 );
            if ( $address_2 ) {
                update_user_meta( $customer_id, 'billing_address_2', $address_2 );
            }
            update_user_meta( $customer_id, 'billing_postcode', $postcode );
            update_user_meta( $customer_id, 'billing_city', $city );
            update_user_meta( $customer_id, 'billing_country', $country );

            // Shipping metadata (fallback to billing if not separated)
            $s_first_name = $get( 'shipping_first_name' ) ?: $first_name;
            $s_last_name  = $get( 'shipping_last_name' ) ?: $last_name;
            $s_company    = $get( 'shipping_company' ) ?: $company;
            $s_address_1  = $get( 'shipping_address_1' ) ?: $address_1;
            $s_address_2  = $get( 'shipping_address_2' ) ?: $address_2;
            $s_postcode   = $get( 'shipping_postcode' ) ?: $postcode;
            $s_city       = $get( 'shipping_city' ) ?: $city;
            $s_country    = $get( 'shipping_country' ) ?: $country;

            update_user_meta( $customer_id, 'shipping_first_name', $s_first_name );
            update_user_meta( $customer_id, 'shipping_last_name', $s_last_name );
            update_user_meta( $customer_id, 'shipping_company', $s_company );
            update_user_meta( $customer_id, 'shipping_address_1', $s_address_1 );
            if ( $s_address_2 ) {
                update_user_meta( $customer_id, 'shipping_address_2', $s_address_2 );
            }
            update_user_meta( $customer_id, 'shipping_postcode', $s_postcode );
            update_user_meta( $customer_id, 'shipping_city', $s_city );
            update_user_meta( $customer_id, 'shipping_country', $s_country );

            // Automatically log in newly created customer
            wp_set_current_user( $customer_id );
            wp_set_auth_cookie( $customer_id );
            if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
                wc_set_customer_auth_cookie( $customer_id );
            }
        }

        /**
         * Kadence Advanced Form: Fail the submission if account creation failed (also skips the Redirect action)
         */
        public function kadence_submission_success( $success, $form_args, $processed_fields, $post_id, $submission_results ) {
            if ( ! empty( self::$kadence_error ) && $this->is_kadence_b2b_form( $this->kadence_extract_fields( $processed_fields ) ) ) {
                return false;
            }
            return $success;
        }

        /**
         * Kadence Advanced Form: Show the account creation error instead of Kadence's generic "Submission Failed"
         */
        public function kadence_submission_messages( $messages, $form_args, $processed_fields, $post_id, $submission_results ) {
            if ( ! empty( self::$kadence_error ) && $this->is_kadence_b2b_form( $this->kadence_extract_fields( $processed_fields ) ) ) {
                $messages['error'] = esc_html( self::$kadence_error );
            }
            return $messages;
        }

        /**
         * Handle B2B Registration Form POST Submission (Shortcode fallback)
         */
        public function handle_b2b_registration_submit() {
            if ( ! isset( $_POST['dyc_b2b_submit'] ) ) {
                return;
            }

            if ( ! isset( $_POST['dyc_b2b_nonce'] ) || ! wp_verify_nonce( $_POST['dyc_b2b_nonce'], 'dyc_b2b_register' ) ) {
                wc_add_notice( __( 'Säkerhetskontrollen misslyckades. Vänligen ladda om sidan och försök igen.', 'dyc-b2b-registration' ), 'error' );
                return;
            }

            $errors = new WP_Error();

            $orgnr       = isset( $_POST['billing_orgnr'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_orgnr'] ) ) : '';
            $company     = isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '';
            $first_name  = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
            $last_name   = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
            $email       = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
            $inv_email   = isset( $_POST['billing_invoice_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_invoice_email'] ) ) : '';
            $phone       = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
            $address_1   = isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '';
            $postcode    = isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '';
            $city        = isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '';
            $country     = isset( $_POST['billing_country'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : 'SE';
            $password    = isset( $_POST['password'] ) ? $_POST['password'] : '';
            $password_c  = isset( $_POST['password_confirm'] ) ? $_POST['password_confirm'] : '';

            $diff_shipping = ! empty( $_POST['ship_to_different_address'] );
            $s_address_1   = $diff_shipping && isset( $_POST['shipping_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ) ) : $address_1;
            $s_postcode    = $diff_shipping && isset( $_POST['shipping_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ) ) : $postcode;
            $s_city        = $diff_shipping && isset( $_POST['shipping_city'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_city'] ) ) : $city;

            if ( empty( $orgnr ) ) {
                $errors->add( 'empty_orgnr', __( 'Organisationsnummer är obligatoriskt.', 'dyc-b2b-registration' ) );
            }
            if ( empty( $company ) ) {
                $errors->add( 'empty_company', __( 'Företagsnamn är obligatoriskt.', 'dyc-b2b-registration' ) );
            }
            if ( empty( $first_name ) || empty( $last_name ) ) {
                $errors->add( 'empty_name', __( 'Kontaktperson (för- och efternamn) är obligatoriskt.', 'dyc-b2b-registration' ) );
            }
            if ( empty( $email ) || ! is_email( $email ) ) {
                $errors->add( 'invalid_email', __( 'Vänligen ange en giltig e-postadress.', 'dyc-b2b-registration' ) );
            } elseif ( email_exists( $email ) ) {
                $errors->add( 'email_exists', __( 'En användare med denna e-postadress finns redan registrerad.', 'dyc-b2b-registration' ) );
            } elseif ( self::is_public_email_domain( $email ) ) {
                $errors->add( 'public_email', __( 'Vänligen ange en företags-e-postadress. Privata e-posttjänster (Gmail, Hotmail, etc.) tillåts inte för B2B-konton.', 'dyc-b2b-registration' ) );
            }
            if ( ! empty( $inv_email ) && ! is_email( $inv_email ) ) {
                $errors->add( 'invalid_inv_email', __( 'Vänligen ange en giltig faktura-e-postadress.', 'dyc-b2b-registration' ) );
            }
            if ( empty( $address_1 ) || empty( $postcode ) || empty( $city ) ) {
                $errors->add( 'empty_address', __( 'Faktureringsadress (gatuadress, postnummer och ort) är obligatorisk.', 'dyc-b2b-registration' ) );
            }
            if ( empty( $password ) || strlen( $password ) < 8 ) {
                $errors->add( 'invalid_password', __( 'Lösenordet måste bestå av minst 8 tecken.', 'dyc-b2b-registration' ) );
            } elseif ( $password !== $password_c ) {
                $errors->add( 'password_mismatch', __( 'Lösenorden matchar inte.', 'dyc-b2b-registration' ) );
            }

            $clean_org = preg_replace( '/\D/', '', $orgnr );
            if ( strlen( $clean_org ) === 12 ) {
                $clean_org = substr( $clean_org, 2 );
            }
            if ( strlen( $clean_org ) !== 10 || ! self::is_valid_luhn( $clean_org ) ) {
                $errors->add( 'invalid_orgnr', __( 'Ogiltigt organisationsnummer. Kontrollera att det är ett korrekt 10-siffrigt nummer.', 'dyc-b2b-registration' ) );
            } else {
                $vies = self::query_vies_vat( $clean_org );
                if ( $vies !== null && empty( $vies['isValid'] ) ) {
                    $errors->add( 'vies_failed', __( 'Organisationsnumret kunde inte verifieras som ett aktivt momsregistrerat företag hos Skatteverket/VIES.', 'dyc-b2b-registration' ) );
                }
            }

            if ( $errors->has_errors() ) {
                foreach ( $errors->get_error_messages() as $msg ) {
                    wc_add_notice( $msg, 'error' );
                }
                return;
            }

            $formatted_orgnr = substr( $clean_org, 0, 6 ) . '-' . substr( $clean_org, 6 );
            $username        = self::generate_unique_username( $email );
            $customer_id     = wc_create_new_customer( $email, $username, $password );

            if ( is_wp_error( $customer_id ) ) {
                wc_add_notice( $customer_id->get_error_message(), 'error' );
                return;
            }

            $user = new WP_User( $customer_id );
            $user->set_role( 'business' );

            update_user_meta( $customer_id, 'first_name', $first_name );
            update_user_meta( $customer_id, 'last_name', $last_name );
            update_user_meta( $customer_id, 'billing_first_name', $first_name );
            update_user_meta( $customer_id, 'billing_last_name', $last_name );
            update_user_meta( $customer_id, 'billing_company', $company );
            update_user_meta( $customer_id, 'billing_orgnr', $formatted_orgnr );
            update_user_meta( $customer_id, 'billing_email', $email );
            if ( $inv_email ) {
                update_user_meta( $customer_id, 'billing_invoice_email', $inv_email );
            }
            update_user_meta( $customer_id, 'billing_phone', $phone );
            update_user_meta( $customer_id, 'billing_address_1', $address_1 );
            update_user_meta( $customer_id, 'billing_postcode', $postcode );
            update_user_meta( $customer_id, 'billing_city', $city );
            update_user_meta( $customer_id, 'billing_country', $country );

            update_user_meta( $customer_id, 'shipping_first_name', $first_name );
            update_user_meta( $customer_id, 'shipping_last_name', $last_name );
            update_user_meta( $customer_id, 'shipping_company', $company );
            update_user_meta( $customer_id, 'shipping_address_1', $s_address_1 );
            update_user_meta( $customer_id, 'shipping_postcode', $s_postcode );
            update_user_meta( $customer_id, 'shipping_city', $s_city );
            update_user_meta( $customer_id, 'shipping_country', $country );

            wp_set_current_user( $customer_id );
            wp_set_auth_cookie( $customer_id );
            if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
                wc_set_customer_auth_cookie( $customer_id );
            }

            wc_add_notice( __( 'Välkommen! Ditt företagskonto har registrerats och du är nu inloggad.', 'dyc-b2b-registration' ), 'success' );
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        /**
         * Render the Standalone B2B Registration Shortcode Form
         */
        public function render_b2b_registration_form() {
            if ( is_user_logged_in() ) {
                $user = wp_get_current_user();
                $account_url = wc_get_page_permalink( 'myaccount' );
                return '<div class="woocommerce-message" style="margin-bottom:20px;">' . 
                    sprintf( __( 'Du är redan inloggad som <strong>%s</strong>. Gå till <a href="%s">Mina sidor</a>.', 'dyc-b2b-registration' ), esc_html( $user->display_name ), esc_url( $account_url ) ) . 
                    '</div>';
            }

            ob_start();
            $nonce = wp_create_nonce( 'dyc_b2b_register' );
            $ajax_nonce = wp_create_nonce( 'dyc_b2b_nonce' );
            ?>
            <div class="dyc-b2b-registration-wrapper">
                <?php if ( function_exists( 'wc_print_notices' ) ) wc_print_notices(); ?>

                <form method="post" class="dyc-b2b-form" id="dyc-b2b-registration-form">
                    <?php wp_nonce_field( 'dyc_b2b_register', 'dyc_b2b_nonce' ); ?>

                    <div class="dyc-b2b-section">
                        <h3><?php _e( 'Företagsuppgifter', 'dyc-b2b-registration' ); ?></h3>

                        <div class="dyc-form-row dyc-orgnr-row">
                            <label for="dyc_billing_orgnr"><?php _e( 'Organisationsnummer', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                            <div class="dyc-input-with-button">
                                <input type="text" name="billing_orgnr" id="dyc_billing_orgnr" placeholder="t.ex. 556074-3089" value="<?php echo ! empty( $_POST['billing_orgnr'] ) ? esc_attr( $_POST['billing_orgnr'] ) : ''; ?>" required />
                                <button type="button" id="dyc-lookup-btn" class="button"><?php _e( 'Hämta uppgifter', 'dyc-b2b-registration' ); ?></button>
                            </div>
                            <div id="dyc-orgnr-status" class="dyc-status-feedback"></div>
                        </div>

                        <div class="dyc-form-row">
                            <label for="dyc_billing_company"><?php _e( 'Företagsnamn', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                            <input type="text" name="billing_company" id="dyc_billing_company" value="<?php echo ! empty( $_POST['billing_company'] ) ? esc_attr( $_POST['billing_company'] ) : ''; ?>" required />
                        </div>
                    </div>

                    <div class="dyc-b2b-section">
                        <h3><?php _e( 'Kontaktperson & Inloggning', 'dyc-b2b-registration' ); ?></h3>

                        <div class="dyc-grid-2">
                            <div class="dyc-form-row">
                                <label for="dyc_billing_first_name"><?php _e( 'Förnamn', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="text" name="billing_first_name" id="dyc_billing_first_name" value="<?php echo ! empty( $_POST['billing_first_name'] ) ? esc_attr( $_POST['billing_first_name'] ) : ''; ?>" required />
                            </div>
                            <div class="dyc-form-row">
                                <label for="dyc_billing_last_name"><?php _e( 'Efternamn', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="text" name="billing_last_name" id="dyc_billing_last_name" value="<?php echo ! empty( $_POST['billing_last_name'] ) ? esc_attr( $_POST['billing_last_name'] ) : ''; ?>" required />
                            </div>
                        </div>

                        <div class="dyc-grid-2">
                            <div class="dyc-form-row">
                                <label for="dyc_billing_email"><?php _e( 'Företags-e-postadress', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="email" name="billing_email" id="dyc_billing_email" placeholder="namn@foretag.se" value="<?php echo ! empty( $_POST['billing_email'] ) ? esc_attr( $_POST['billing_email'] ) : ''; ?>" required />
                                <small class="dyc-help-text"><?php _e( 'Ange din företags-e-post (Gmail/Hotmail etc. accepteras ej).', 'dyc-b2b-registration' ); ?></small>
                            </div>
                            <div class="dyc-form-row">
                                <label for="dyc_billing_invoice_email"><?php _e( 'Faktura-e-post (valfri)', 'dyc-b2b-registration' ); ?></label>
                                <input type="email" name="billing_invoice_email" id="dyc_billing_invoice_email" placeholder="faktura@foretag.se" value="<?php echo ! empty( $_POST['billing_invoice_email'] ) ? esc_attr( $_POST['billing_invoice_email'] ) : ''; ?>" />
                            </div>
                        </div>

                        <div class="dyc-grid-2">
                            <div class="dyc-form-row">
                                <label for="dyc_billing_phone"><?php _e( 'Telefonnummer', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="tel" name="billing_phone" id="dyc_billing_phone" value="<?php echo ! empty( $_POST['billing_phone'] ) ? esc_attr( $_POST['billing_phone'] ) : ''; ?>" required />
                            </div>
                            <div></div>
                        </div>

                        <div class="dyc-grid-2">
                            <div class="dyc-form-row">
                                <label for="dyc_password"><?php _e( 'Lösenord (minst 8 tecken)', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="password" name="password" id="dyc_password" required autocomplete="new-password" />
                            </div>
                            <div class="dyc-form-row">
                                <label for="dyc_password_confirm"><?php _e( 'Bekräfta lösenord', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="password" name="password_confirm" id="dyc_password_confirm" required autocomplete="new-password" />
                            </div>
                        </div>
                    </div>

                    <div class="dyc-b2b-section">
                        <h3><?php _e( 'Faktureringsadress', 'dyc-b2b-registration' ); ?></h3>

                        <div class="dyc-form-row">
                            <label for="dyc_billing_address_1"><?php _e( 'Gatuadress', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                            <input type="text" name="billing_address_1" id="dyc_billing_address_1" value="<?php echo ! empty( $_POST['billing_address_1'] ) ? esc_attr( $_POST['billing_address_1'] ) : ''; ?>" required />
                        </div>

                        <div class="dyc-grid-2">
                            <div class="dyc-form-row">
                                <label for="dyc_billing_postcode"><?php _e( 'Postnummer', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="text" name="billing_postcode" id="dyc_billing_postcode" value="<?php echo ! empty( $_POST['billing_postcode'] ) ? esc_attr( $_POST['billing_postcode'] ) : ''; ?>" required />
                            </div>
                            <div class="dyc-form-row">
                                <label for="dyc_billing_city"><?php _e( 'Postort', 'dyc-b2b-registration' ); ?> <span class="required">*</span></label>
                                <input type="text" name="billing_city" id="dyc_billing_city" value="<?php echo ! empty( $_POST['billing_city'] ) ? esc_attr( $_POST['billing_city'] ) : ''; ?>" required />
                            </div>
                        </div>
                    </div>

                    <div class="dyc-b2b-section">
                        <div class="dyc-form-row dyc-checkbox-row">
                            <label>
                                <input type="checkbox" name="ship_to_different_address" id="dyc_ship_diff" value="1" <?php checked( ! empty( $_POST['ship_to_different_address'] ) ); ?> />
                                <?php _e( 'Leveransadressen är en annan än faktureringsadressen', 'dyc-b2b-registration' ); ?>
                            </label>
                        </div>

                        <div id="dyc-shipping-fields" style="<?php echo empty( $_POST['ship_to_different_address'] ) ? 'display:none;' : ''; ?>">
                            <h3><?php _e( 'Leveransadress', 'dyc-b2b-registration' ); ?></h3>

                            <div class="dyc-form-row">
                                <label for="dyc_shipping_address_1"><?php _e( 'Leveransadress', 'dyc-b2b-registration' ); ?></label>
                                <input type="text" name="shipping_address_1" id="dyc_shipping_address_1" value="<?php echo ! empty( $_POST['shipping_address_1'] ) ? esc_attr( $_POST['shipping_address_1'] ) : ''; ?>" />
                            </div>

                            <div class="dyc-grid-2">
                                <div class="dyc-form-row">
                                    <label for="dyc_shipping_postcode"><?php _e( 'Postnummer', 'dyc-b2b-registration' ); ?></label>
                                    <input type="text" name="shipping_postcode" id="dyc_shipping_postcode" value="<?php echo ! empty( $_POST['shipping_postcode'] ) ? esc_attr( $_POST['shipping_postcode'] ) : ''; ?>" />
                                </div>
                                <div class="dyc-form-row">
                                    <label for="dyc_shipping_city"><?php _e( 'Postort', 'dyc-b2b-registration' ); ?></label>
                                    <input type="text" name="shipping_city" id="dyc_shipping_city" value="<?php echo ! empty( $_POST['shipping_city'] ) ? esc_attr( $_POST['shipping_city'] ) : ''; ?>" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dyc-form-submit">
                        <button type="submit" name="dyc_b2b_submit" value="1" class="button alt wp-element-button">
                            <?php _e( 'Registrera företagskonto', 'dyc-b2b-registration' ); ?>
                        </button>
                    </div>
                </form>
            </div>

            <style>
                .dyc-b2b-registration-wrapper {
                    max-width: 760px;
                    margin: 20px auto;
                    background: #fff;
                    padding: 30px;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
                }
                .dyc-b2b-section {
                    margin-bottom: 25px;
                    padding-bottom: 20px;
                    border-bottom: 1px solid #f1f5f9;
                }
                .dyc-b2b-section:last-of-type {
                    border-bottom: none;
                }
                .dyc-b2b-section h3 {
                    font-size: 1.15rem;
                    margin-bottom: 15px;
                    color: #1e293b;
                    font-weight: 600;
                }
                .dyc-form-row {
                    margin-bottom: 15px;
                }
                .dyc-form-row label {
                    display: block;
                    font-weight: 600;
                    font-size: 0.9rem;
                    margin-bottom: 6px;
                    color: #334155;
                }
                .dyc-form-row input[type="text"],
                .dyc-form-row input[type="email"],
                .dyc-form-row input[type="tel"],
                .dyc-form-row input[type="password"] {
                    width: 100%;
                    padding: 10px 14px;
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    font-size: 0.95rem;
                }
                .dyc-grid-2 {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 15px;
                }
                @media (max-width: 640px) {
                    .dyc-grid-2 {
                        grid-template-columns: 1fr;
                    }
                }
                .dyc-input-with-button {
                    display: flex;
                    gap: 10px;
                }
                .dyc-input-with-button input {
                    flex: 1;
                }
                .dyc-input-with-button button {
                    white-space: nowrap;
                    padding: 0 18px;
                }
                .dyc-status-feedback {
                    font-size: 0.85rem;
                    margin-top: 6px;
                    font-weight: 500;
                }
                .dyc-status-feedback.success {
                    color: #16a34a;
                }
                .dyc-status-feedback.error {
                    color: #dc2626;
                }
                .dyc-help-text {
                    font-size: 0.8rem;
                    color: #64748b;
                    display: block;
                    margin-top: 4px;
                }
                .dyc-checkbox-row label {
                    font-weight: normal;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .dyc-form-submit {
                    margin-top: 20px;
                }
                .dyc-form-submit button {
                    width: 100%;
                    padding: 14px 20px;
                    font-size: 1.05rem;
                    font-weight: 600;
                    border-radius: 6px;
                    cursor: pointer;
                }
            </style>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var shipDiff = document.getElementById('dyc_ship_diff');
                    var shippingFields = document.getElementById('dyc-shipping-fields');
                    if (shipDiff && shippingFields) {
                        shipDiff.addEventListener('change', function() {
                            shippingFields.style.display = this.checked ? 'block' : 'none';
                        });
                    }

                    var lookupBtn = document.getElementById('dyc-lookup-btn');
                    var orgnrInput = document.getElementById('dyc_billing_orgnr');
                    var statusEl = document.getElementById('dyc-orgnr-status');
                    var companyInput = document.getElementById('dyc_billing_company');

                    function lookupOrgnr() {
                        var val = orgnrInput.value.trim();
                        if (!val) {
                            statusEl.className = 'dyc-status-feedback error';
                            statusEl.textContent = 'Vänligen fyll i organisationsnummer.';
                            return;
                        }

                        statusEl.className = 'dyc-status-feedback';
                        statusEl.textContent = 'Kontrollerar hos Skatteverket/VIES...';
                        lookupBtn.disabled = true;

                        var formData = new FormData();
                        formData.append('action', 'dyc_lookup_orgnr');
                        formData.append('nonce', '<?php echo esc_js( $ajax_nonce ); ?>');
                        formData.append('orgnr', val);

                        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            lookupBtn.disabled = false;
                            if (res.success) {
                                statusEl.className = 'dyc-status-feedback success';
                                statusEl.textContent = res.data.message;
                                if (res.data.formatted) {
                                    orgnrInput.value = res.data.formatted;
                                }
                                if (res.data.company_name && (!companyInput.value || companyInput.value.trim() === '')) {
                                    companyInput.value = res.data.company_name;
                                }
                            } else {
                                statusEl.className = 'dyc-status-feedback error';
                                statusEl.textContent = res.data.message || 'Kunde inte verifiera.';
                            }
                        })
                        .catch(function() {
                            lookupBtn.disabled = false;
                            statusEl.className = 'dyc-status-feedback error';
                            statusEl.textContent = 'Kunde inte kontakta valideringstjänsten.';
                        });
                    }

                    if (lookupBtn) {
                        lookupBtn.addEventListener('click', lookupOrgnr);
                    }
                    if (orgnrInput) {
                        orgnrInput.addEventListener('blur', function() {
                            if (this.value.trim().length >= 10 && !companyInput.value.trim()) {
                                lookupOrgnr();
                            }
                        });
                    }
                });
            </script>
            <?php
            return ob_get_clean();
        }
    }

    // Initialize the plugin
    DYC_B2B_Registration::get_instance();
}