<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class CampTix_Khalti_Payment_Method extends CampTix_Payment_Method
{
    public $id = 'camptix_khalti';
    public $name = 'Khalti';
    public $description = 'CampTix payment methods for Khalti Gateway';
    public $supported_currencies = array('NPR');


    protected $options = array();

    function camptix_init()
    {
        $this->options = array_merge(array(
            'ref_code' => '',
            'merchant_key' => '',
            'sandbox' => true,
        ), $this->get_payment_options());

        add_action('template_redirect', array($this, 'template_redirect'));
    }

    function payment_settings_fields()
    {
        $this->add_settings_field_helper('ref_code', 'Reference Code', array($this, 'field_text'));
        $this->add_settings_field_helper('merchant_key', 'Merchant Key', array($this, 'field_text'));
        $this->add_settings_field_helper('sandbox', 'Sandbox Mode', array($this, 'field_yesno'));
    }

    function validate_options($input)
    {
        $output = $this->options;
        if (isset($input['ref_code']))
            $output['ref_code'] = $input['ref_code'];
        if (isset($input['merchant_key']))
            $output['merchant_key'] = $input['merchant_key'];
        if (isset($input['sandbox']))
            $output['sandbox'] = (bool) $input['sandbox'];

        return $output;
    }

    function template_redirect()
    {
        if (!isset($_REQUEST['tix_payment_method']) || 'camptix_khalti' != $_REQUEST['tix_payment_method'])
            return;
        if (isset($_GET['tix_action'])) {
            if ('payment_cancel' == $_GET['tix_action']) {
                $this->payment_cancel();
            }

            if ('payment_return' == $_GET['tix_action']) {
                $this->payment_return();
            }
        }
    }

    function payment_return()
    {
        global $camptix;

        $this->log(sprintf('Running payment_return. Request data attached.'), null, $_REQUEST);
        $this->log(sprintf('Running payment_return. Server data attached.'), null, $_SERVER);

        $payment_token = (isset($_REQUEST['tix_payment_token'])) ? trim($_REQUEST['tix_payment_token']) : '';
        $payment_token = (isset($_REQUEST['tix_payment_token'])) ? trim($_REQUEST['tix_payment_token']) : '';
        if (empty($payment_token)) {
            return;
        }
        $pidx = isset($_GET['pidx']) ?  $_GET['pidx'] : "";
        $status = $this->verify_transaction($pidx);

        switch ($status) {
            case "Completed":
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_COMPLETED);
                break;
            case "Expired":
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_TIMEOUT);
                break;
            case "User canceled":
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED);
                break;
            default:
                $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_PENDING);
                break;
        }

        $attendees = get_posts(
            array(
                'posts_per_page' => 1,
                'post_type'      => 'tix_attendee',
                'post_status'    => array('draft', 'pending', 'publish', 'cancel', 'refund', 'failed'),
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
        if (empty($attendees)) {
            return;
        }
        $attendee = reset($attendees);
        $access_token = get_post_meta($attendee->ID, 'tix_access_token', true);
        $url          = add_query_arg(array(
            'tix_action'       => 'access_tickets',
            'tix_access_token' => $access_token,
        ), $camptix->get_tickets_url());
        wp_safe_redirect(esc_url_raw($url . '#tix'));
        die();
    }

    public function payment_checkout($payment_token)
    {
        global $camptix;

        if (!$payment_token || empty($payment_token))
            return false;
        if (!in_array($this->camptix_options['currency'], $this->supported_currencies))
            die(__('The selected currency is not supported by this payment method.', 'camptix'));
        $return_url = add_query_arg(array(
            'tix_action' => 'payment_return',
            'tix_payment_token' => $payment_token,
            'tix_payment_method' => 'camptix_khalti',
        ), $this->get_tickets_url());


        $order = $this->get_order($payment_token);


        $buyer_name = trim(get_post_meta($order['attendee_id'], 'tix_first_name', true) . ' ' . get_post_meta($order['attendee_id'], 'tix_last_name', true));
        $buyer_email = get_post_meta($order['attendee_id'], 'tix_email', true);
        $buyer_phone = get_post_meta($order['attendee_id'], 'tix_phone', true);

        $item = reset($order['items']);

        if (isset($this->options["ref_code"])) {
            $order_name = $this->options['ref_code'] . '-' . $item["name"];
        } else {
            $order_name = $item["name"];
        }

        $purchase_order_id = $payment_token;
        $purchase_order_name = sanitize_text_field($order_name);
        $amount       = intval($order['total'] * 100);
        $customer_name  = sanitize_text_field($buyer_name);
        $customer_email = sanitize_email($buyer_email);
        $customer_phone =  sanitize_text_field($buyer_phone);

        $payload = [
            'return_url'          => esc_url_raw($return_url),
            'website_url'         => esc_url_raw(home_url()),
            'amount'              => $amount,
            'purchase_order_id'   => $purchase_order_id,
            'purchase_order_name' => $purchase_order_name,
            'customer_info'       => [
                'name'  => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
            ],
        ];

        $merchant_key = $this->options['merchant_key'];
        $headers = [
            'Authorization' => 'key ' . sanitize_text_field($merchant_key),
            'Content-Type'  => 'application/json',
        ];

        $url = $this->options["sandbox"] ? "https://dev.khalti.com/api/v2/epayment/initiate/" : "https://khalti.com/api/v2/epayment/initiate/";

        $remote_response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($payload)
        ));

        if (is_wp_error($remote_response)) {
            $error_message = $remote_response->get_error_message();
            $this->log(sprintf("Remote Request failed:" . $error_message . ': %s', null, "failed_remote_request"));
            return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
        } else {
            $result = json_decode(wp_remote_retrieve_body($remote_response), true);
            if (isset($result['payment_url'])) {
                echo '<script>
                let url = "' . esc_js($result['payment_url']) . '";
                if (window.location.hash) {
                  history.replaceState(null, null, window.location.href.split("#")[0]);
                }
                window.location.href = url;
                </script>';
                exit;
            } else {
                return $this->payment_result($payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED);
            }

            return;
        }
    }

    function verify_transaction($pidx)
    {
        $merchant_key = $this->options['merchant_key'];

        $headers = [
            'Authorization' => 'key ' . sanitize_text_field($merchant_key),
            'Content-Type'  => 'application/json',
        ];

        $url = $this->options["sandbox"] ? "https://dev.khalti.com/api/v2/epayment/lookup/" : "https://khalti.com/api/v2/epayment/lookup/";

        $payload = [
            'pidx' => $pidx
        ];
        $remote_response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => wp_json_encode($payload)
        ));

        if (is_wp_error($remote_response)) {
            $error_message = $remote_response->get_error_message();
            $this->log(sprintf("Remote Request failed:" . $error_message . ': %s', null, "failed_remote_request"));
            return "Pending";
        } else {
            $result = json_decode(wp_remote_retrieve_body($remote_response), true);
            return isset($result['status']) ? $result['status'] : "Pending";
        }
    }
}
