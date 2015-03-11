<?php
/**
 * Plugin Name: coinzone-woocommerce
 * Plugin URI: http://coinzone.com
 * Description: WooCommerce Bitcoin payments integration with Coinzone.
 * Version: 1.1.2
 * Author: Coinzone
 * Author URI: http://coinzone.com
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Check if WooCommerce is installed
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

	/**
     *
     */
    function initCoinzone()
    {

        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_Gateway_Coinzone extends WC_Payment_Gateway
        {

            public function __construct()
            {
                require_once(plugin_dir_path(__FILE__) . 'lib' . DIRECTORY_SEPARATOR . 'Coinzone.php');

                $this->id = 'coinzone';
                $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/bitcoin.jpg';
                $this->has_fields = false;
                $this->method_title = __('Coinzone', 'coinzone');
                $this->method_description = __('Pay with bitcoin', 'coinzone');
                $this->view_transaction_url = 'https://merchant.coinzone.com/transactions?search=%s';
                $this->supports[] = 'refunds';

                $this->init_form_fields();
                $this->init_settings();

                $this->title = $this->get_option('title');

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                    $this,
                    'updateCoinzoneOptions'
                ));

                // Payment listener/API hook
                add_action('woocommerce_api_wc_gateway_coinzone', array(
                    $this,
                    'processCoinzoneIpn'
                ));

                add_action( 'wp_enqueue_scripts', array( $this, 'paymentScripts' ) );

                add_filter('woocommerce_gateway_title', array(
                    $this,
                    'checkoutPaymentTitle'
                ), 10, 2);
            }

            /** ADMIN SECTION */

            /**
             * Mandatory method that adds the custom settings on the Coinzone Admin Page
             * @return void
             */
            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'coinzone'),
                        'type' => 'checkbox',
                        'label' => __('Enable Coinzone Payment', 'coinzone'),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title' => __('Title', 'coinzone'),
                        'type' => 'text',
                        'description' => __('This controls the title that the user sees during checkout.', 'coinzone'),
                        'default' => __('Coinzone', 'coinzone'),
                        'desc_tip' => true
                    ),
                    'description' => array(
                        'title' => __('Checkout Message', 'coinzone'),
                        'type' => 'textarea',
                        'description' => __('This controls the description that the user sees during checkout.',
                            'coinzone'),
                        'desc_tip' => true,
                        'default' => __('Pay with bitcoin, a virtual currency.', 'coinzone')
                            . " <a href='http://bitcoin.org/' target='_blank'>"
                            . __('What is bitcoin?', 'coinzone')
                            . "</a>"
                    ),
                    'clientCode' => array(
                        'title' => __('Client Code', 'coinzone'),
                        'type' => 'text',
                        'description' => __('The client code provided by Coinzone.', 'coinzone'),
                        'default' => '',
                        'desc_tip' => true
                    ),
                    'apiKey' => array(
                        'title' => __('API Key', 'coinzone'),
                        'type' => 'text',
                        'description' => __('The API Key provided by Coinzone.', 'coinzone'),
                        'default' => '',
                        'desc_tip' => true
                    )
                );
            }

            /**
             * admin_options overwritten to show errors on the Coinzone Admin Page
             */
            public function admin_options()
            {
                echo '<h3>' . __('Coinzone Payment Gateway', 'coizone') . '</h3>';
                echo '<p>Add your Client Code and API Key below to configure Coinzone.
                    This can be found on the API tab of the Settings page in the
                    <a href="https://merchant.coinzone.com/login#apiTab" target="_blank">Coinzone control panel</a>.</p>';
                echo '<p>Have questions?  Please visit our <a href="http://support.coinzone.com/" target="_blank">customer support site</a>.</p>';
                echo '<p>Don\'t have a Coinzone account?  <a href="https://merchant.coinzone.com/signup?source=woocommerce" target="_blank">Sign up for free.</a></p>';

                $coinzoneErrorMessages = get_option("coinzoneErrorMessages");
                delete_option('coinzoneErrorMessages');
                if (!empty($coinzoneErrorMessages)) {
                    foreach ($coinzoneErrorMessages as $coinzoneErrorMessage) {
                        echo '<div id="error" class="error fade"><p>' . $coinzoneErrorMessage . '</p></div>';
                    }
                }
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';
            }

            /**
             * Validate client code and api key
             * @return bool
             */
            public function updateCoinzoneOptions()
            {

                delete_option('coinzoneErrorMessages');
                $coinzoneErrorMessages = array();

                if (empty($_POST[$this->plugin_id . $this->id . '_clientCode'])) {
                    array_push($coinzoneErrorMessages, __('Please fill the Client Code field.', 'coinzone'));
                }

                if (empty($_POST[$this->plugin_id . $this->id . '_apiKey'])) {
                    array_push($coinzoneErrorMessages, __('Please fill the API Key field.', 'coinzone'));
                }

                update_option('coinzoneErrorMessages', $coinzoneErrorMessages);

                if (!parent::process_admin_options()) {
                    return false;
                }
            }

            /** END ADMIN SECTION */


            public function process_payment($orderId)
            {

                global $woocommerce;
                $order = new WC_Order($orderId);

                $apiKey = $this->get_option('apiKey');
                $clientCode = $this->get_option('clientCode');

                $redirectUrl = add_query_arg('return_from_coinzone', true, $this->get_return_url($order));

                if (!function_exists('curl_init')) {
                    $woocommerce->add_error(__('Sorry, but there was an error processing your order. Please try again or try a different payment method. (curl not enabled on server)',
                        'coinzone'));
                    return;
                }

                if (empty($apiKey) || empty($clientCode)) {
                    $woocommerce->add_error(__('Sorry, but there was an error processing your order. Please try again or try a different payment method. (plugin not configured)',
                        'coinzone'));
                    return;
                }

                try {

//                    /* add products ordered to API request */
//                    $items = $order->get_items();
//                    $displayItems = array();
//                    foreach ($items as $item) {
//                        $product = $order->get_product_from_item($item);
//                        $displayItems[] = array(
//                            'name' => $product->get_title(),
//                            'quantity' => (int)$item['qty'],
//                            'unitPrice' => $product->get_price(),
//                            'shortDescription' => substr($product->get_post_data()->post_excerpt, 0, 250),
//                            'imageUrl' => wp_get_attachment_image_src($product->get_image_id())[0]
//                        );
//                    }

                    // Create payload array
                    $payload = array(
                        'amount' => $order->get_total(),
                        'currency' => get_woocommerce_currency(),
                        'merchantReference' => $orderId,
                        'email' => $order->billing_email,
                        'redirectUrl' => $redirectUrl,
                        'notificationUrl' => $notify_url = WC()->api_request_url('WC_Gateway_Coinzone'),
//                        'displayOrderInformation' => array(
//                            'items' => $displayItems,
//                            'tax' => WC()->cart->get_taxes_total(true, true),
//                            'shippingCost' => $order->get_total_shipping(),
//                            'discount' => $order->get_order_discount()
//                        ),
                        'userAgent' => 'wooCommerce ' . WC_VERSION . ' - Plugin Version 1.1.2'
                    );

                    $coinzone = new Coinzone($apiKey, $clientCode);
                    $response = $coinzone->callApi('transaction', $payload);

                    if ($response->status->code !== 201) {
                        $order->add_order_note(__('Error while processing coinbase payment:',
                                'coinzone') . ' ' . $response->status->message);
                        wc_add_notice(__('Sorry, but there was an error processing your order. Please try again or try a different payment method.',
                                'coinzone'), 'error');

                        return;
                    }
                } catch (Exception $e) {
                    $order->add_order_note(__('Error while processing coinbase payment:',
                            'coinzone') . ' ' . $e->getMessage());
                    wc_add_notice(__('Sorry, but there was an error processing your order. Please try again or try a different payment method.',
                            'coinzone'), 'error');

                    return;
                }

                update_post_meta($orderId, __('Coinzone Transaction ID', 'coinzone'), wc_clean($response->response->refNo));
                update_post_meta($orderId, '_transaction_id', wc_clean($response->response->refNo));


                // Reduce stock levels
                $order->reduce_order_stock();

                // Remove cart
                $woocommerce->cart->empty_cart();


                return array(
                    'result' => 'success',
                    'redirect' => $response->response->url
                );

            }

            /**
             * Process a refund if supported
             * @param  int $order_id
             * @param  float $amount
             * @param  string $reason
             * @return  bool|wp_error True or false based on success, or a WP_Error object
             */
            public function process_refund($order_id, $amount = null, $reason = '')
            {

                $order = wc_get_order($order_id);

                $apiKey = $this->get_option('apiKey');
                $clientCode = $this->get_option('clientCode');

                if (!$order || !$order->get_transaction_id() || !$apiKey || !$clientCode) {
                    return false;
                }

                try {

                    /* refund order to API request */

                    // Create payload array
                    $payload = array(
                        'amount' => $amount,
                        'currency' => get_woocommerce_currency(),
                        'refNo' => $order->get_transaction_id(),
                        'reason' => $reason,
                        'userAgent' => 'wooCommerce ' . WC_VERSION . ' - Plugin Version 1.1.2'
                    );

                    $coinzone = new Coinzone($apiKey, $clientCode);
                    $response = $coinzone->callApi('cancel_request', $payload);

                    if ($response->status->code !== 201) {
                        $order->add_order_note(__('Error while processing coinbase payment refund:',
                                'coinzone') . ' ' . $response->status->message);
                        return new WP_Error('coinzone_refund_error', $response->status->message);
                    }
                } catch (Exception $e) {
                    $order->add_order_note(__('Error while processing coinbase payment refund:',
                            'coinzone') . ' ' . $e->getMessage());
                    return new WP_Error('coinzone_refund_error', $response->status->message);
                }

                $refunds = $order->get_refunds();
                if (!empty($refunds)) {
                    $refund = reset($refunds);
                    $refundId = $refund->id;

                    wp_update_post(array(
                            'ID' => $refundId,
                            'post_excerpt' => '[' . __('Pending Refund', 'coinzone') . '] ' . $refund->post->post_excerpt
                        ));
                }


                return true;
            }

            public function processCoinzoneIpn()
            {
                $response = file_get_contents('php://input');


                $apiKey = $this->get_option('apiKey');
                $clientCode = $this->get_option('clientCode');

                $coinzone = new Coinzone($apiKey, $clientCode);
                $coinzone->checkRequest($response);

                if (!empty($response)) {

                    $response = preg_replace('/\s+/', '', $response);
                    $decodedResponse = json_decode($response, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        parse_str( $response, $decodedResponse );
                    }

                    if (
                        !is_array($decodedResponse) ||
                        !isset($decodedResponse['merchantReference']) ||
                        !isset($decodedResponse['refNo']) ||
                        !isset($decodedResponse['amount']) ||
                        !isset($decodedResponse['currency']) ||
                        !isset($decodedResponse['convertedAmount']) ||
                        !isset($decodedResponse['convertedCurrency']) ||
                        !isset($decodedResponse['status'])
                    ) {
                        http_response_code(400);
                        die(
                        json_encode(
                            array(
                                'error' => true,
                                'message' => __('Invalid Notification format.')
                            )
                            )
                        );
                    }

                    $order = new WC_Order($decodedResponse['merchantReference']);

                    switch (strtolower($decodedResponse['status'])) {

                        case 'paid':
                        case 'complete':

                            // Check order not already completed
                            if ($order->status == 'completed') {
                                exit;
                            }

                            $order->add_order_note(__('Coinzone payment completed', 'coinzone'));
                            $order->payment_complete($decodedResponse['refNo']);

                            break;
                        case 'refund':
                            // Check order not already refunded
                            $refundFound = false;
                            foreach (array_reverse($order->get_refunds()) as $refund) {
                                if ($refund->get_refund_amount() == $decodedResponse['amount']) {
                                    $reason = preg_replace('/^\[' . __('Pending Refund', 'coinzone') . '\] /', '', $refund->post->post_excerpt);
                                    if ($reason != $refund->post->post_excerpt) {
                                        wp_update_post(array(
                                            'ID' => $refund->id,
                                            'post_excerpt' => '[' . __('Completed Refund', 'coinzone') . '] ' . $reason
                                        ));

                                        // Mark order as refunded
                                        $order->add_order_note(sprintf(__('Coinzone payment completed a refund of %s.', 'coinzone'), wc_price( $decodedResponse['amount'])));
                                        $order->update_status('refunded', __('Refunded', 'coinzone'));

                                        $refundFound = true;

                                        break;
                                    }
                                }
                            }

                            if ($refundFound === false){
                                $order->add_order_note(sprintf(__('Failed to find a refund of %s.', 'coinzone'), wc_price( $decodedResponse['amount'])));
                            }


                            break;
                        case 'canceled':

                            $order->update_status('failed',
                                __('Coinbase reports payment cancelled.', 'coinbase-woocommerce'));
                            break;

                    }

                    exit;


                }

            }

            public function checkoutPaymentTitle($title, $id) {
                if (empty($post)) {
                    return $id == 'coinzone' ? '<span class="coinzone-hidden">'.$title.'</span>' : $title;
                } else {
                    return $title;
                }
            }

            public function paymentScripts() {
                wp_enqueue_style( 'coinzone-css', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/css/style.css' );
            }

        }

        /**
         * Add this Coinzone Gateway to WooCommerce
         **/
        function addCoinzoneGateway($methods)
        {
            $methods[] = 'WC_Gateway_Coinzone';
            return $methods;
        }

        add_filter('woocommerce_payment_gateways', 'addCoinzoneGateway');

    }

    add_action('plugins_loaded', 'initCoinzone');
}