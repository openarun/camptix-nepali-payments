<?php
/**
 * Khalti Payment Gateway for CampTix
 *
 * @package CampTix_Nepali_Payments
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Khalti Payment Gateway Class
 */
class CampTix_Khalti_Payment_Method extends CampTix_Payment_Method {
    /**
     * Payment gateway ID
     *
     * @var string
     */
    public $id = 'camptix_khalti';

    /**
     * Payment gateway name
     *
     * @var string
     */
    public $name = 'Khalti';

    /**
     * Payment gateway description
     *
     * @var string
     */
    public $description = 'CampTix payment methods for Khalti Gateway';

    /**
     * Supported currencies
     *
     * @var array
     */
    public $supported_currencies = array( 'NPR' );

    /**
     * Gateway options
     *
     * @var array
     */
    protected $options = array();

    /**
     * Initialize the gateway
     *
     * @return void
     */
    public function camptix_init() {
        $this->options = array_merge(
            array(
                'ref_code'      => '',
                'merchant_key'  => '',
                'sandbox'       => true,
            ),
            $this->get_payment_options()
        );

        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
    }

    /**
     * Add settings fields
     *
     * @return void
     */
    public function payment_settings_fields() {
        $this->add_settings_field_helper( 'ref_code', __( 'Reference Code', 'camptix-nepali-payments' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'merchant_key', __( 'Merchant Key', 'camptix-nepali-payments' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix-nepali-payments' ), array( $this, 'field_yesno' ) );
    }

    /**
     * Validate options
     *
     * @param array $input Input data.
     * @return array
     */
    public function validate_options( $input ) {
        $output = $this->options;

        if ( isset( $input['ref_code'] ) ) {
            $output['ref_code'] = sanitize_text_field( $input['ref_code'] );
        }
        if ( isset( $input['merchant_key'] ) ) {
            $output['merchant_key'] = sanitize_text_field( $input['merchant_key'] );
        }
        if ( isset( $input['sandbox'] ) ) {
            $output['sandbox'] = (bool) $input['sandbox'];
        }

        return $output;
    }

    /**
     * Handle template redirect
     *
     * @return void
     */
    public function template_redirect() {
        if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'camptix_khalti' !== sanitize_text_field( wp_unslash( $_REQUEST['tix_payment_method'] ) ) ) {
            return;
        }

        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'camptix_nepali_payments' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'camptix-nepali-payments' ) );
        }

        if ( isset( $_GET['tix_action'] ) && !empty(sanitize_text_field( wp_unslash( $_GET['tix_action'] ) )) ) {
            $action = sanitize_text_field( wp_unslash( $_GET['tix_action'] ) );
            if ( 'payment_return' === $action ) {
                $this->payment_return();
            }
        }
    }

    /**
     * Handle payment return
     *
     * @return void
     */
    public function payment_return() {
        global $camptix;

        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'camptix_nepali_payments' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'camptix-nepali-payments' ) );
        }

        $payment_token = isset( $_REQUEST['tix_payment_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tix_payment_token'] ) ) : '';
        if ( empty( $payment_token ) ) {
            return;
        }

        $pidx = isset( $_GET['pidx'] ) ? sanitize_text_field( wp_unslash( $_GET['pidx'] ) ) : '';
        $status = $this->verify_transaction( $pidx );

        switch ( $status ) {
            case 'Completed':
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED, $_GET );
                break;
            case 'Pending':
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING, $_GET );
                break;
            case 'Expired':
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_TIMEOUT, $_GET );
                break;
            case 'Initiated':
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING, $_GET );
                break;
            case 'User canceled':
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED, $_GET );
                break;
            default:
                $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $_GET );
                $this->log( 'Unknown camptix khalti payment status for pidx' .$pidx.':'. esc_html( $status ) );
                break;
        }

        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type'      => 'tix_attendee',
                'post_status'    => array( 'draft', 'pending', 'publish', 'cancel', 'refund', 'failed' ),
                'meta_query'     => array(
                    array(
                        'key'     => 'tix_payment_token',
                        'compare' => '=',
                        'value'   => $payment_token,
                        'type'    => 'CHAR',
                    ),
                ),
            )
        );

        if ( empty( $attendees ) ) {
            return;
        }

        $attendee = reset( $attendees );
        $access_token = get_post_meta( $attendee->ID, 'tix_access_token', true );
        $url = add_query_arg(
            array(
                'tix_action'       => 'access_tickets',
                'tix_access_token' => $access_token,
            ),
            $camptix->get_tickets_url()
        );

        wp_safe_redirect( esc_url_raw( $url . '#tix' ) );
        exit;
    }

    /**
     * Handle payment checkout
     *
     * @param string $payment_token Payment token.
     * @return bool|void
     */
    public function payment_checkout( $payment_token ) {
        if ( ! $payment_token || empty( $payment_token ) ) {
            return false;
        }

        if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies, true ) ) {
            wp_die( esc_html__( 'The selected currency is not supported by this payment method.', 'camptix-nepali-payments' ) );
        }

        $return_url = add_query_arg(
            array(
                'tix_action'         => 'payment_return',
                'tix_payment_token'  => $payment_token,
                'tix_payment_method' => 'camptix_khalti',
                '_wpnonce'           => wp_create_nonce( 'camptix_nepali_payments' ),
            ),
            $this->get_tickets_url()
        );

        $order = $this->get_order( $payment_token );
        if ( ! $order ) {
            return false;
        }

        $buyer_name = trim(
            get_post_meta( $order['attendee_id'], 'tix_first_name', true ) . ' ' .
            get_post_meta( $order['attendee_id'], 'tix_last_name', true )
        );
        $buyer_email = get_post_meta( $order['attendee_id'], 'tix_email', true );
        $buyer_phone = get_post_meta( $order['attendee_id'], 'tix_phone', true );

        $item = reset( $order['items'] );
        if ( ! $item ) {
            return false;
        }

        $order_name = isset( $this->options['ref_code'] ) && ! empty( $this->options['ref_code'] )
            ? $this->options['ref_code'] . '-' . $item['name']
            : $item['name'];

        $purchase_order_id = $payment_token;
        $purchase_order_name = sanitize_text_field( $order_name );
        $amount = intval( $order['total'] * 100 );
        $customer_name = sanitize_text_field( $buyer_name );
        $customer_email = sanitize_email( $buyer_email );

        if ( ! is_email( $customer_email ) ) {
            $this->log( 'Invalid email format provided: ' . esc_html( $buyer_email ) );
            return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
        }

        $customer_phone = sanitize_text_field( $buyer_phone );

        $payload = array(
            'return_url'          => esc_url_raw( $return_url ),
            'website_url'         => esc_url_raw( home_url() ),
            'amount'              => $amount,
            'purchase_order_id'   => $purchase_order_id,
            'purchase_order_name' => $purchase_order_name,
            'customer_info'       => array(
                'name'  => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
            ),
        );

        $merchant_key = $this->options['merchant_key'];
        $headers = array(
            'Authorization' => 'key ' . sanitize_text_field( $merchant_key ),
            'Content-Type'  => 'application/json',
        );

        $url = $this->options['sandbox']
            ? 'https://dev.khalti.com/api/v2/epayment/initiate/'
            : 'https://khalti.com/api/v2/epayment/initiate/';

        $remote_response = wp_remote_post(
            $url,
            array(
                'method'    => 'POST',
                'headers'   => $headers,
                'body'      => wp_json_encode( $payload ),
                'timeout'   => 15,
                'blocking'  => true,
            )
        );

        if ( is_wp_error( $remote_response ) ) {
            $error_message = $remote_response->get_error_message();
            $this->log( 'Khalti Remote Request failed: ' . esc_html( $error_message ) );
            return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
        }

        $result = json_decode( wp_remote_retrieve_body( $remote_response ), true );
        if ( isset( $result['payment_url'] ) ) {
            // Redirect to Khalti payment URL using JavaScript to avoid #tix issue in the URL
            ?>
            <script type="text/javascript">
                window.location.href = <?php echo wp_json_encode( $result['payment_url'] ); ?>;
            </script>
            <p>Redirecting you to Khalti Payment</p>
            <?php
            exit;
        }

        return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED );
    }

    /**
     * Verify transaction
     *
     * @param string $pidx Transaction ID.
     * @return string
     */
    public function verify_transaction( $pidx ) {
        if ( empty( $pidx ) ) {
            return 'Failed';
        }

        $merchant_key = $this->options['merchant_key'];
        $headers = array(
            'Authorization' => 'key ' . sanitize_text_field( $merchant_key ),
            'Content-Type'  => 'application/json',
        );

        $url = $this->options['sandbox']
            ? 'https://dev.khalti.com/api/v2/epayment/lookup/'
            : 'https://khalti.com/api/v2/epayment/lookup/';

        $payload = array(
            'pidx' => sanitize_text_field( $pidx ),
        );

        $remote_response = wp_remote_post(
            $url,
            array(
                'method'    => 'POST',
                'headers'   => $headers,
                'body'      => wp_json_encode( $payload ),
                'timeout'   => 15,
                'blocking'  => true,
            )
        );

        if ( is_wp_error( $remote_response ) ) {
            $error_message = $remote_response->get_error_message();
            $this->log( sprintf( 'Remote Request failed: %s', esc_html( $error_message ) ) );
            return 'Pending';
        }

        $result = json_decode( wp_remote_retrieve_body( $remote_response ), true );
        return isset( $result['status'] ) ? sanitize_text_field( $result['status'] ) : 'Unknown';
    }
}
