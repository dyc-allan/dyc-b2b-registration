<?php
/**
 * Sync business customers (role 'business') to Briox.
 *
 * Decisions (BRIOX_INTEGRATION_PLAN.md, Phase 2):
 * - One Briox customer per WordPress user, even if the org.nr already exists in Briox.
 *   The Briox customer number is stored in user meta and the user is never created twice.
 * - Briox assigns the customer number.
 * - New customers get the payment terms from the settings (default 15 days), price list A, invoices
 *   by print and no reminder email.
 *   The invoice template (DYC-Faktura) is the Briox account default; the API can't set it.
 * - My Account address changes update the Briox customer.
 *
 * PUT /customer/{custno} updates the same customer but clears every field not sent, so updates read the
 * current customer, change only the fields this plugin owns and send the complete object back.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'DYC_Briox_Sync' ) ) {

    class DYC_Briox_Sync {

        const HOOK            = 'dyc_briox_sync_customer';
        const KEEP_ALIVE_HOOK = 'dyc_briox_keep_token_alive';
        const GROUP           = 'dyc-briox';

        const META_CUSTNO   = '_dyc_briox_customer_id';
        const META_STATUS   = '_dyc_briox_sync';
        const META_ATTEMPTS = '_dyc_briox_attempts';
        const META_PENDING  = '_dyc_briox_create_pending';

        // Seconds to wait before retry 1, 2, 3 ... after a temporary error
        const RETRY_DELAYS = array( 60, 300, 1800, 7200, 43200 );

        private static $instance = null;

        /** @var DYC_Briox_Client */
        private $client;

        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            $this->client = new DYC_Briox_Client();

            // Triggers
            add_action( 'dyc_b2b_customer_registered', array( $this, 'queue_sync' ) );
            add_action( 'woocommerce_customer_save_address', array( $this, 'queue_sync' ) );
            add_action( 'personal_options_update', array( $this, 'queue_sync' ) );
            add_action( 'edit_user_profile_update', array( $this, 'queue_sync' ) );

            // Worker (Action Scheduler)
            add_action( self::HOOK, array( $this, 'run_scheduled_sync' ) );

            // Daily token keep-alive: the auth token can't be exchanged again, so the refresh chain must not lapse
            add_action( 'action_scheduler_init', array( $this, 'schedule_keep_alive' ) );
            add_action( self::KEEP_ALIVE_HOOK, array( $this, 'keep_token_alive' ) );
            add_action( 'admin_notices', array( $this, 'render_connection_notice' ) );

            // Admin: status on the user profile + "Sync now"
            add_action( 'show_user_profile', array( $this, 'render_profile_section' ), 30 );
            add_action( 'edit_user_profile', array( $this, 'render_profile_section' ), 30 );
            add_action( 'admin_post_dyc_briox_sync_now', array( $this, 'handle_sync_now' ) );
            add_action( 'admin_notices', array( $this, 'render_sync_now_notice' ) );
        }

        /**
         * Only business customers are synced
         */
        public static function is_syncable_user( $user_id ) {
            $user = get_userdata( (int) $user_id );
            return $user && in_array( 'business', (array) $user->roles, true );
        }

        /* ------------------------------------------------------------------
         * Queueing
         * ------------------------------------------------------------------ */

        /**
         * Queue a background sync for a user (deduplicated). Extra hook arguments are ignored.
         */
        public function queue_sync( $user_id ) {
            $user_id = (int) $user_id;
            if ( ! self::is_syncable_user( $user_id ) ) {
                return;
            }

            // A new change starts a fresh retry series
            delete_user_meta( $user_id, self::META_ATTEMPTS );

            if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( $user_id ), self::GROUP ) ) {
                return;
            }
            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( self::HOOK, array( $user_id ), self::GROUP );
            } else {
                wp_schedule_single_event( time(), self::HOOK, array( $user_id ) );
            }
            $this->set_status( $user_id, 'pending', __( 'Väntar på synkning.', 'dyc-b2b-registration' ) );
        }

        /**
         * Action Scheduler callback: sync, and schedule a retry on temporary errors
         */
        public function run_scheduled_sync( $user_id ) {
            $user_id = (int) $user_id;
            $result  = $this->sync_user( $user_id );

            if ( ! is_wp_error( $result ) ) {
                return;
            }

            $data      = $result->get_error_data();
            $retryable = is_array( $data ) && ! empty( $data['retryable'] );
            $attempts  = (int) get_user_meta( $user_id, self::META_ATTEMPTS, true ) + 1;

            if ( $retryable && $attempts <= count( self::RETRY_DELAYS ) ) {
                update_user_meta( $user_id, self::META_ATTEMPTS, $attempts );
                $delay = self::RETRY_DELAYS[ $attempts - 1 ];
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    as_schedule_single_action( time() + $delay, self::HOOK, array( $user_id ), self::GROUP );
                } else {
                    wp_schedule_single_event( time() + $delay, self::HOOK, array( $user_id ) );
                }
                $this->set_status( $user_id, 'retrying', sprintf( 'Försök %d misslyckades (%s). Nytt försök om %d min.', $attempts, $result->get_error_message(), (int) ceil( $delay / 60 ) ) );
                self::log( 'warning', sprintf( 'User %d: attempt %d failed (%s), retry in %ds', $user_id, $attempts, $result->get_error_message(), $delay ) );
            } elseif ( $retryable ) {
                $this->set_status( $user_id, 'error', sprintf( 'Gav upp efter %d försök: %s', $attempts, $result->get_error_message() ) );
                self::log( 'error', sprintf( 'User %d: giving up after %d attempts: %s', $user_id, $attempts, $result->get_error_message() ) );
            }
            // Non-retryable errors already got their status in sync_user()
        }

        /* ------------------------------------------------------------------
         * Token keep-alive
         * ------------------------------------------------------------------ */

        public function schedule_keep_alive() {
            if ( ! DYC_Briox_Client::is_configured() || ! function_exists( 'as_schedule_recurring_action' ) ) {
                return;
            }
            if ( ! as_has_scheduled_action( self::KEEP_ALIVE_HOOK, array(), self::GROUP ) ) {
                as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::KEEP_ALIVE_HOOK, array(), self::GROUP );
            }
        }

        /**
         * Refreshes the access token when it is within the client's refresh margin (3 days)
         */
        public function keep_token_alive() {
            $token = $this->client->get_access_token();
            if ( is_wp_error( $token ) ) {
                self::log( 'error', 'Token keep-alive failed: ' . $token->get_error_message() );
            }
        }

        /**
         * Tell administrators when the Briox connection is broken and how to fix it
         */
        public function render_connection_notice() {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            // Don't repeat the warning on the Briox settings tab itself, which shows the status
            if ( isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'dyc_briox' === $_GET['tab'] ) {
                return;
            }

            if ( DYC_Briox_Settings::site_mismatch() ) {
                printf(
                    '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                    esc_html__( 'Briox är inte anslutet på den här webbplatsen.', 'dyc-b2b-registration' ),
                    esc_html__( 'Briox-uppgifterna sparades på en annan webbplats (t.ex. en kopia av databasen) och används inte här.', 'dyc-b2b-registration' ),
                    esc_url( DYC_Briox_Settings::settings_url() ),
                    esc_html__( 'Briox-inställningar', 'dyc-b2b-registration' )
                );
                return;
            }

            $error = $this->client->connection_error();
            if ( ! $error ) {
                return;
            }
            $where = 'wp-config' === DYC_Briox_Settings::credentials()['source']
                ? __( 'ersätt DYC_BRIOX_AUTH_TOKEN i wp-config.php', 'dyc-b2b-registration' )
                : __( 'ange den under WooCommerce → Inställningar → Briox', 'dyc-b2b-registration' );
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s <a href="%s">%s</a></p></div>',
                esc_html__( 'Briox-anslutningen fungerar inte.', 'dyc-b2b-registration' ),
                esc_html( sprintf( __( 'Fel sedan %1$s: %2$s', 'dyc-b2b-registration' ), wp_date( 'Y-m-d H:i', (int) $error['time'] ), $error['message'] ) ),
                esc_html( sprintf( __( 'Nya företagskunder synkas inte till Briox. Generera en ny autentiseringstoken i Briox (Admin → Användare → Externa applikationer) och %s. Synka sedan berörda kunder med knappen "Synka till Briox nu" på användarens profil.', 'dyc-b2b-registration' ), $where ) ),
                esc_url( DYC_Briox_Settings::settings_url() ),
                esc_html__( 'Briox-inställningar', 'dyc-b2b-registration' )
            );
        }

        /* ------------------------------------------------------------------
         * Sync
         * ------------------------------------------------------------------ */

        /**
         * Create or update the user's Briox customer.
         *
         * @return string|WP_Error Briox customer number on success.
         */
        public function sync_user( $user_id ) {
            $user_id = (int) $user_id;

            if ( ! self::is_syncable_user( $user_id ) ) {
                return new WP_Error( 'dyc_briox_not_business', 'User is not a business customer.', array( 'retryable' => false ) );
            }
            if ( ! DYC_Briox_Client::is_configured() ) {
                $error = new WP_Error( 'dyc_briox_not_configured', 'Briox-uppgifter saknas i wp-config.php.', array( 'retryable' => false ) );
                return $this->fail( $user_id, $error );
            }

            $custno = (string) get_user_meta( $user_id, self::META_CUSTNO, true );

            // A previous create may have succeeded without us getting the answer: find it before creating again
            if ( '' === $custno && get_user_meta( $user_id, self::META_PENDING, true ) ) {
                $recovered = $this->find_lost_create( $user_id );
                if ( is_wp_error( $recovered ) ) {
                    return $this->fail( $user_id, $recovered );
                }
                if ( '' !== $recovered ) {
                    $custno = $recovered;
                    update_user_meta( $user_id, self::META_CUSTNO, $custno );
                    self::log( 'info', sprintf( 'User %d: recovered Briox customer %s from an earlier create', $user_id, $custno ) );
                }
                delete_user_meta( $user_id, self::META_PENDING );
            }

            if ( '' !== $custno ) {
                $result = $this->update_customer( $user_id, $custno );
                // Deleted in Briox: create a new one
                if ( DYC_Briox_Client::is_not_found( $result ) ) {
                    self::log( 'warning', sprintf( 'User %d: Briox customer %s no longer exists, creating a new one', $user_id, $custno ) );
                    delete_user_meta( $user_id, self::META_CUSTNO );
                    $result = $this->create_customer( $user_id );
                }
            } else {
                $result = $this->create_customer( $user_id );
            }

            if ( is_wp_error( $result ) ) {
                return $this->fail( $user_id, $result );
            }

            delete_user_meta( $user_id, self::META_ATTEMPTS );
            $this->set_status( $user_id, 'ok', '', $result );
            return $result;
        }

        private function create_customer( $user_id ) {
            $customer = array_merge( self::defaults(), self::owned_fields( $user_id ) );

            update_user_meta( $user_id, self::META_PENDING, time() );
            $data = $this->client->request( 'POST', '/customer', array( 'customer' => $customer ) );

            if ( is_wp_error( $data ) ) {
                $status = $data->get_error_data()['status'] ?? 0;
                // Briox answered with a clear rejection, so nothing was created
                if ( $status >= 400 && $status < 500 ) {
                    delete_user_meta( $user_id, self::META_PENDING );
                }
                return $data;
            }

            $custno = isset( $data['customer']['custno'] ) ? (string) $data['customer']['custno'] : '';
            if ( '' === $custno ) {
                return new WP_Error( 'dyc_briox_no_custno', 'Briox returned no customer number.', array( 'retryable' => true ) );
            }

            update_user_meta( $user_id, self::META_CUSTNO, $custno );
            delete_user_meta( $user_id, self::META_PENDING );
            self::log( 'info', sprintf( 'User %d: created Briox customer %s', $user_id, $custno ) );
            return $custno;
        }

        private function update_customer( $user_id, $custno ) {
            $current = $this->client->request( 'GET', '/customer/' . rawurlencode( $custno ) );
            if ( is_wp_error( $current ) ) {
                return $current;
            }
            if ( empty( $current['customer'] ) || ! is_array( $current['customer'] ) ) {
                return new WP_Error( 'dyc_briox_bad_response', 'Unexpected customer response from Briox.', array( 'retryable' => true ) );
            }

            $customer = self::merge_owned_fields( $current['customer'], self::owned_fields( $user_id ) );
            $customer['custno'] = $custno;

            $data = $this->client->request( 'PUT', '/customer/' . rawurlencode( $custno ), array( 'customer' => $customer ) );
            if ( is_wp_error( $data ) ) {
                return $data;
            }

            self::log( 'info', sprintf( 'User %d: updated Briox customer %s', $user_id, $custno ) );
            return $custno;
        }

        /**
         * After an unanswered create: look for a customer with this user's email and org.nr.
         *
         * @return string|WP_Error Customer number, '' if none found.
         */
        private function find_lost_create( $user_id ) {
            $fields = self::owned_fields( $user_id );
            $data   = $this->client->request( 'GET', '/customer/email/' . rawurlencode( $fields['email'] ) );

            if ( DYC_Briox_Client::is_not_found( $data ) ) {
                return '';
            }
            if ( is_wp_error( $data ) ) {
                return $data;
            }

            // data.customer is a list here (several customers can share an email)
            $best = '';
            foreach ( (array) ( $data['customer'] ?? array() ) as $candidate ) {
                if ( isset( $candidate['companynumber'], $candidate['custno'] ) && $candidate['companynumber'] === $fields['companynumber'] ) {
                    if ( '' === $best || (int) $candidate['custno'] > (int) $best ) {
                        $best = (string) $candidate['custno'];
                    }
                }
            }
            return $best;
        }

        private function fail( $user_id, WP_Error $error ) {
            $data = $error->get_error_data();
            if ( empty( $data['retryable'] ) ) {
                $this->set_status( $user_id, 'error', $error->get_error_message() );
                self::log( 'error', sprintf( 'User %d: %s', $user_id, $error->get_error_message() ) );
            }
            return $error;
        }

        /* ------------------------------------------------------------------
         * Mapping
         * ------------------------------------------------------------------ */

        /**
         * Settings for new Briox customers only (never sent on update, so changes made in Briox stick)
         */
        public static function defaults() {
            return apply_filters(
                'dyc_briox_customer_defaults',
                array(
                    'active'                        => '1',
                    'customerbusinesstype'          => '1',
                    'currency'                      => 'SEK',
                    'customertype'                  => 0,
                    'paymentterms'                  => DYC_Briox_Settings::payment_terms(), // WooCommerce → Settings → Briox
                    'pricelist'                     => 'A',
                    'invoicedocumentdeliverymethod' => '00', // print
                )
            );
        }

        /**
         * Customer data that WordPress owns: sent on create and overwritten on every update
         */
        public static function owned_fields( $user_id ) {
            $meta = function ( $key ) use ( $user_id ) {
                return trim( (string) get_user_meta( $user_id, $key, true ) );
            };
            $user = get_userdata( $user_id );

            $first   = $meta( 'billing_first_name' );
            $last    = $meta( 'billing_last_name' );
            $contact = trim( $first . ' ' . $last );
            $company = $meta( 'billing_company' ) ?: $contact;

            $digits    = DYC_B2B_Registration::clean_orgnr( $meta( 'billing_orgnr' ) );
            $orgnr     = 10 === strlen( $digits ) ? substr( $digits, 0, 6 ) . '-' . substr( $digits, 6 ) : $meta( 'billing_orgnr' );
            $vatnumber = 10 === strlen( $digits ) ? 'SE' . $digits . '01' : '';

            $email     = $meta( 'billing_email' ) ?: ( $user ? $user->user_email : '' );
            $inv_email = $meta( 'billing_invoice_email' ) ?: $email;

            $country      = $meta( 'billing_country' ) ?: 'SE';
            $delivery     = $meta( 'shipping_company' ) ?: $company;
            $has_shipping = '' !== $meta( 'shipping_address_1' );
            $ship         = function ( $key ) use ( $meta, $has_shipping ) {
                return $meta( ( $has_shipping ? 'shipping_' : 'billing_' ) . $key );
            };

            return array(
                'name'          => $company,
                'companynumber' => $orgnr,
                'vatnumber'     => $vatnumber,
                'email'         => $email,
                'phone'         => $meta( 'billing_phone' ),
                'customerref'   => $contact,
                'deliveryname'  => $delivery,
                'address'       => array(
                    array(
                        'type'         => 'invoice',
                        'addressline1' => $meta( 'billing_address_1' ),
                        'addressline2' => $meta( 'billing_address_2' ),
                        'zip'          => $meta( 'billing_postcode' ),
                        'city'         => $meta( 'billing_city' ),
                        'countrycode'  => $country,
                    ),
                    array(
                        'type'         => 'delivery',
                        'name'         => $delivery,
                        'addressline1' => $ship( 'address_1' ),
                        'addressline2' => $ship( 'address_2' ),
                        'zip'          => $ship( 'postcode' ),
                        'city'         => $ship( 'city' ),
                        'countrycode'  => $ship( 'country' ) ?: $country,
                    ),
                ),
                // Invoice email only; reminders get no email (decision 2.3)
                'edocument'     => array(
                    array( 'type' => 'invoice', 'email' => $inv_email, 'cc' => '', 'bcc' => '' ),
                ),
            );
        }

        /**
         * Overlay our fields on the customer as it is in Briox (PUT clears every field not sent)
         */
        public static function merge_owned_fields( array $current, array $owned ) {
            // Read-only / informational fields Briox returns but doesn't accept
            unset( $current['has_kivra'] );

            foreach ( $owned as $key => $value ) {
                if ( 'address' === $key || 'edocument' === $key ) {
                    continue;
                }
                $current[ $key ] = $value;
            }

            // Replace entries by type, keep any others (e.g. order/quote/reminder emails set in Briox)
            foreach ( array( 'address', 'edocument' ) as $list ) {
                $merged = array();
                foreach ( (array) ( $current[ $list ] ?? array() ) as $entry ) {
                    if ( isset( $entry['type'] ) ) {
                        $merged[ $entry['type'] ] = $entry;
                    }
                }
                foreach ( $owned[ $list ] as $entry ) {
                    $merged[ $entry['type'] ] = array_merge( $merged[ $entry['type'] ] ?? array(), $entry );
                }
                $current[ $list ] = array_values( $merged );
            }

            return $current;
        }

        /* ------------------------------------------------------------------
         * Status, logging
         * ------------------------------------------------------------------ */

        private function set_status( $user_id, $status, $message = '', $custno = '' ) {
            update_user_meta(
                $user_id,
                self::META_STATUS,
                array(
                    'status'  => $status,
                    'message' => $message,
                    'custno'  => $custno,
                    'time'    => time(),
                )
            );
        }

        public static function log( $level, $message ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->log( $level, $message, array( 'source' => 'dyc-briox' ) );
            }
        }

        /* ------------------------------------------------------------------
         * Admin
         * ------------------------------------------------------------------ */

        public function render_profile_section( $user ) {
            if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_syncable_user( $user->ID ) ) {
                return;
            }

            $custno = (string) get_user_meta( $user->ID, self::META_CUSTNO, true );
            $status = (array) get_user_meta( $user->ID, self::META_STATUS, true );
            $labels = array(
                'ok'       => __( 'Synkad', 'dyc-b2b-registration' ),
                'pending'  => __( 'Väntar', 'dyc-b2b-registration' ),
                'retrying' => __( 'Försöker igen', 'dyc-b2b-registration' ),
                'error'    => __( 'Fel', 'dyc-b2b-registration' ),
            );
            $state   = $status['status'] ?? '';
            $sync_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=dyc_briox_sync_now&user_id=' . $user->ID ),
                'dyc_briox_sync_now_' . $user->ID
            );
            ?>
            <h2><?php esc_html_e( 'Briox', 'dyc-b2b-registration' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e( 'Kundnummer i Briox', 'dyc-b2b-registration' ); ?></th>
                    <td><?php echo $custno ? esc_html( $custno ) : '<em>' . esc_html__( 'Inte skapad ännu', 'dyc-b2b-registration' ) . '</em>'; ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Synkstatus', 'dyc-b2b-registration' ); ?></th>
                    <td>
                        <?php
                        echo esc_html( $labels[ $state ] ?? __( 'Aldrig synkad', 'dyc-b2b-registration' ) );
                        if ( ! empty( $status['time'] ) ) {
                            echo ' &middot; ' . esc_html( wp_date( 'Y-m-d H:i', (int) $status['time'] ) );
                        }
                        if ( ! empty( $status['message'] ) ) {
                            echo '<br><span class="description">' . esc_html( $status['message'] ) . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <a href="<?php echo esc_url( $sync_url ); ?>" class="button"><?php esc_html_e( 'Synka till Briox nu', 'dyc-b2b-registration' ); ?></a>
                        <?php if ( 'prod' !== DYC_Briox_Client::env() ) : ?>
                            <span class="description"><?php echo esc_html( sprintf( __( 'Miljö: %s', 'dyc-b2b-registration' ), DYC_Briox_Client::env() ) ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <?php
        }

        /**
         * "Sync now" button: run the sync immediately and show the result
         */
        public function handle_sync_now() {
            $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
            if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
                wp_die( esc_html__( 'Du har inte behörighet att göra detta.', 'dyc-b2b-registration' ), 403 );
            }
            check_admin_referer( 'dyc_briox_sync_now_' . $user_id );

            $result = $this->sync_user( $user_id );

            if ( is_wp_error( $result ) ) {
                $this->set_status( $user_id, 'error', $result->get_error_message() );
            }

            $redirect = add_query_arg(
                array(
                    'user_id'        => $user_id,
                    'dyc_briox_sync' => is_wp_error( $result ) ? 'error' : 'ok',
                ),
                admin_url( 'user-edit.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        public function render_sync_now_notice() {
            if ( empty( $_GET['dyc_briox_sync'] ) || empty( $_GET['user_id'] ) ) {
                return;
            }
            $ok     = 'ok' === $_GET['dyc_briox_sync'];
            $status = (array) get_user_meta( absint( $_GET['user_id'] ), self::META_STATUS, true );
            $text   = $ok
                ? sprintf( __( 'Synkad till Briox (kundnummer %s).', 'dyc-b2b-registration' ), $status['custno'] ?? '' )
                : sprintf( __( 'Synkning till Briox misslyckades: %s', 'dyc-b2b-registration' ), $status['message'] ?? '' );
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $text ) );
        }
    }
}
