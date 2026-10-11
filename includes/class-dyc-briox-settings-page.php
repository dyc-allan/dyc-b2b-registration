<?php
/**
 * WooCommerce → Settings → Briox
 *
 * Loaded from the woocommerce_get_settings_pages filter, when WC_Settings_Page exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'DYC_Briox_Settings_Page' ) && class_exists( 'WC_Settings_Page' ) ) {

    class DYC_Briox_Settings_Page extends WC_Settings_Page {

        public function __construct() {
            $this->id    = 'dyc_briox';
            $this->label = __( 'Briox', 'dyc-b2b-registration' );
            parent::__construct();

            add_action( 'woocommerce_admin_field_dyc_briox_status', array( $this, 'render_status_field' ) );
        }

        protected function get_settings_for_default_section() {
            $stored  = DYC_Briox_Settings::stored();
            $locked  = DYC_Briox_Settings::defined_in_config();
            $creds   = DYC_Briox_Settings::credentials();
            $disable = $locked ? array( 'disabled' => 'disabled' ) : array();
            $note    = $locked ? __( 'Definierad i wp-config.php och kan inte ändras här.', 'dyc-b2b-registration' ) : '';

            $token_hint = '';
            if ( '' !== $creds['auth_token'] ) {
                $token_hint = sprintf( __( 'Sparad (slutar på …%s). Lämna tomt för att behålla.', 'dyc-b2b-registration' ), substr( $creds['auth_token'], -4 ) );
            }

            return array(
                array(
                    'title' => __( 'Briox-anslutning', 'dyc-b2b-registration' ),
                    'type'  => 'title',
                    'desc'  => __( 'Nya företagskunder skapas som kunder i Briox, och adressändringar skickas dit.', 'dyc-b2b-registration' ),
                    'id'    => 'dyc_briox_connection',
                ),
                array(
                    'title' => __( 'Status', 'dyc-b2b-registration' ),
                    'type'  => 'dyc_briox_status',
                    'id'    => 'dyc_briox_status',
                ),
                array(
                    'title'             => __( 'Klient-ID', 'dyc-b2b-registration' ),
                    'id'                => 'dyc_briox_client_id',
                    'type'              => 'text',
                    'value'             => $locked ? $creds['client_id'] : $stored['client_id'],
                    'desc'              => $note ?: __( 'Kontonumret under "Ditt konto" i Briox. Byter du konto behövs också en ny autentiseringstoken.', 'dyc-b2b-registration' ),
                    'custom_attributes' => $disable + array( 'autocomplete' => 'off' ),
                ),
                array(
                    'title'             => __( 'Autentiseringstoken', 'dyc-b2b-registration' ),
                    'id'                => 'dyc_briox_auth_token',
                    'type'              => 'password',
                    'value'             => '', // never sent back to the browser
                    'placeholder'       => $token_hint,
                    'desc'              => $note ?: __( 'Genereras i Briox under Admin → Användare → Externa applikationer. Den kan bara användas en gång: den byts mot en anslutning direkt när du sparar.', 'dyc-b2b-registration' ),
                    'custom_attributes' => $disable + array( 'autocomplete' => 'new-password' ),
                ),
                array(
                    'title'             => __( 'Miljö', 'dyc-b2b-registration' ),
                    'id'                => 'dyc_briox_env',
                    'type'              => 'select',
                    'value'             => $locked ? $creds['env'] : $stored['env'],
                    'options'           => array(
                        'test' => __( 'Test', 'dyc-b2b-registration' ),
                        'prod' => __( 'Produktion', 'dyc-b2b-registration' ),
                    ),
                    'desc'              => $note ?: __( 'Bara som påminnelse; visas på användarprofilen.', 'dyc-b2b-registration' ),
                    'custom_attributes' => $disable,
                ),
                array(
                    'type' => 'sectionend',
                    'id'   => 'dyc_briox_connection',
                ),
                array(
                    'title' => __( 'Nya kunder i Briox', 'dyc-b2b-registration' ),
                    'type'  => 'title',
                    'desc'  => __( 'Används när en kund skapas. Ändringar som görs på kunden i Briox skrivs inte över.', 'dyc-b2b-registration' ),
                    'id'    => 'dyc_briox_defaults',
                ),
                array(
                    'title'   => __( 'Betalningsvillkor', 'dyc-b2b-registration' ),
                    'id'      => 'dyc_briox_payment_terms',
                    'type'    => 'select',
                    'value'   => DYC_Briox_Settings::payment_terms(),
                    'options' => DYC_Briox_Settings::payment_term_options(),
                ),
                array(
                    'type' => 'sectionend',
                    'id'   => 'dyc_briox_defaults',
                ),
            );
        }

        /**
         * Status row: which Briox account this site is connected to
         */
        public function render_status_field( $field ) {
            $creds  = DYC_Briox_Settings::credentials();
            $client = new DYC_Briox_Client();
            $lines  = array();

            if ( DYC_Briox_Settings::site_mismatch() ) {
                $lines[] = array( 'error', sprintf( __( 'Uppgifterna sparades på en annan webbplats (%1$s) och används inte här (%2$s). Ange dem på nytt med en ny autentiseringstoken.', 'dyc-b2b-registration' ), DYC_Briox_Settings::stored()['site_url'], DYC_Briox_Settings::current_site() ) );
            } elseif ( ! DYC_Briox_Client::is_configured() ) {
                $lines[] = array( 'warning', __( 'Inte ansluten. Ange klient-ID och autentiseringstoken.', 'dyc-b2b-registration' ) );
            } else {
                $info = $client->request( 'GET', '/company/info' );
                if ( is_wp_error( $info ) ) {
                    $lines[] = array( 'error', sprintf( __( 'Anslutningen fungerar inte: %s', 'dyc-b2b-registration' ), $info->get_error_message() ) );
                } else {
                    $company = $info['company'] ?? $info;
                    $lines[] = array( 'ok', sprintf( __( 'Ansluten till: %1$s (org.nr %2$s)', 'dyc-b2b-registration' ), $company['database_label'] ?? '?', $company['organization_number'] ?? '?' ) );
                    if ( $client->token_expiry() ) {
                        $lines[] = array( '', sprintf( __( 'Token giltig till %s (förnyas automatiskt).', 'dyc-b2b-registration' ), wp_date( 'Y-m-d H:i', $client->token_expiry() ) ) );
                    }
                }
                $lines[] = array( '', 'wp-config' === $creds['source'] ? __( 'Uppgifterna kommer från wp-config.php.', 'dyc-b2b-registration' ) : __( 'Uppgifterna är sparade här.', 'dyc-b2b-registration' ) );
            }

            $colors = array( 'ok' => '#00a32a', 'warning' => '#dba617', 'error' => '#d63638' );
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
                <td class="forminp">
                    <?php foreach ( $lines as $line ) : ?>
                        <p style="margin:0 0 4px;<?php echo isset( $colors[ $line[0] ] ) ? 'color:' . esc_attr( $colors[ $line[0] ] ) . ';font-weight:600;' : ''; ?>"><?php echo esc_html( $line[1] ); ?></p>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php
        }

        /**
         * Save. WooCommerce has already checked the nonce and capability.
         */
        public function save() {
            $stored  = DYC_Briox_Settings::stored();
            $before  = DYC_Briox_Settings::credentials();
            $updated = $stored;

            if ( ! DYC_Briox_Settings::defined_in_config() ) {
                $client_id = isset( $_POST['dyc_briox_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dyc_briox_client_id'] ) ) : '';
                $token     = isset( $_POST['dyc_briox_auth_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dyc_briox_auth_token'] ) ) ) : '';
                $env       = isset( $_POST['dyc_briox_env'] ) && 'prod' === $_POST['dyc_briox_env'] ? 'prod' : 'test';

                // The stored token is already used up, so another account needs a new one too
                if ( '' !== $client_id && '' !== $stored['client_id'] && $client_id !== $stored['client_id'] && '' === $token ) {
                    WC_Admin_Settings::add_error( __( 'Klient-ID:t ändrades inte: ange också en ny autentiseringstoken när du byter Briox-konto.', 'dyc-b2b-registration' ) );
                    $client_id = $stored['client_id'];
                }

                $updated['client_id'] = $client_id;
                $updated['env']       = $env;
                if ( '' !== $token ) {
                    $updated['auth_token'] = $token;
                }
                // A new token, or re-entering the settings on a copied site, binds them to this site
                if ( '' !== $token || '' === $stored['site_url'] ) {
                    $updated['site_url'] = DYC_Briox_Settings::current_site();
                }
            }

            if ( isset( $_POST['dyc_briox_payment_terms'] ) ) {
                $terms = sanitize_text_field( wp_unslash( $_POST['dyc_briox_payment_terms'] ) );
                if ( array_key_exists( $terms, DYC_Briox_Settings::payment_term_options() ) ) {
                    $updated['payment_terms'] = $terms;
                }
            }

            update_option( DYC_Briox_Settings::OPTION, $updated, false );

            // New credentials: exchange the one-time token now, so mistakes show up immediately
            $after = DYC_Briox_Settings::credentials();
            if ( 'settings' === $after['source'] && ( $after['client_id'] !== $before['client_id'] || $after['auth_token'] !== $before['auth_token'] ) ) {
                delete_transient( DYC_Briox_Settings::TERMS_CACHE );
                $token = ( new DYC_Briox_Client() )->get_access_token();
                if ( is_wp_error( $token ) ) {
                    WC_Admin_Settings::add_error( sprintf( __( 'Briox avvisade uppgifterna: %s. Kontrollera klient-ID:t och generera en ny autentiseringstoken (en token kan bara användas en gång).', 'dyc-b2b-registration' ), $token->get_error_message() ) );
                } else {
                    WC_Admin_Settings::add_message( __( 'Ansluten till Briox.', 'dyc-b2b-registration' ) );
                }
            }
        }
    }
}

return new DYC_Briox_Settings_Page();
