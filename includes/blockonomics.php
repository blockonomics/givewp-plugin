<?php

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Helpers\Form\Utils as FormUtils;

/**
 * This class is responsible for communicating with the Blockonomics API
 */
class GiveWpBlockonomics
{
    const BASE_URL = 'https://www.blockonomics.co/api/';
    
    public function __construct()
    {
        $api_key = give_get_option("givewp_blockonomics_api_key");
        $this->api_key = $api_key;
    }

    function get_order_paid_fiat($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'givewp_blockonomics_payments';
        $query = $wpdb->prepare("SELECT expected_fiat,paid_fiat,currency FROM " . $table_name . " WHERE order_id = %d ", $order_id);
        $results = $wpdb->get_results($query, ARRAY_A);
        return $this->calculate_total_paid_fiat($results, $results[0]['currency']);
    }

    public function calculate_total_paid_fiat($transactions, $currency) {
        $currencyList = give_get_currencies_list();
        $number_decimals = $currencyList[$currency]['setting']['number_decimals'];
        $total_paid_fiats = 0.0;
        foreach ($transactions as $transaction) {
            $total_paid_fiats += (float) $transaction['paid_fiat'];
        }
        $rounded_total_paid_fiats = round($total_paid_fiats, $number_decimals, PHP_ROUND_HALF_UP);
        return $rounded_total_paid_fiats;

    }

    public function new_address($secret, $crypto, $reset=false)
    {
        $get_params = "?match_callback=$secret";
        if($reset)
        {
            $get_params += "&reset=1";
        }
        $url = GiveWpBlockonomics::BASE_URL."new_address".$get_params;
        $response = $this->post($url, $this->api_key, '', 8);
        if (!isset($responseObj)) $responseObj = new stdClass();
        $responseObj->{'response_code'} = wp_remote_retrieve_response_code($response);
        if (wp_remote_retrieve_body($response))
        {
          $body = json_decode(wp_remote_retrieve_body($response));
          $responseObj->{'response_message'} = isset($body->message) ? $body->message : '';
          $responseObj->{'address'} = isset($body->address) ? $body->address : '';
        }
        return $responseObj;
    }

    public function get_price($currency, $crypto)
    {
        $url = GiveWpBlockonomics::BASE_URL."price"."?currency=$currency";
        $response = $this->get($url);
        if (!isset($responseObj)) $responseObj = new stdClass();
        $responseObj->{'response_code'} = wp_remote_retrieve_response_code($response);
        if (wp_remote_retrieve_body($response))
        {
          $body = json_decode(wp_remote_retrieve_body($response));
          $responseObj->{'response_message'} = isset($body->message) ? $body->message : '';
          $responseObj->{'price'} = isset($body->price) ? $body->price : '';
        }
        return $responseObj;
    }

    /*
     * Get list of crypto currencies supported by Blockonomics
     */
    public function getSupportedCurrencies() {
        return array(
              'btc' => array(
                    'code' => 'btc',
                    'name' => 'Bitcoin',
                    'uri' => 'bitcoin'
              )
          );
    }

    /*
     * Get list of active crypto currencies
     */
    public function getActiveCurrencies() {
        $active_currencies = array();
        $blockonomics_currencies = $this->getSupportedCurrencies();
        foreach ($blockonomics_currencies as $code => $currency) {
            $enabled = give_get_option('givewp_blockonomics_'.$code);
            if($enabled || ($code === 'btc' && $enabled === false )){
                $active_currencies[$code] = $currency;
            }
        }
        return $active_currencies;
    }

    private function get($url, $api_key = '')
    {
        $headers = $this->set_headers($api_key);
        $response = wp_remote_get( $url, array(
            'method' => 'GET',
            'headers' => $headers
            )
        );
        if(is_wp_error( $response )){
           $error_message = $response->get_error_message();
           echo __('Something went wrong', 'blockonomics-bitcoin-payments').': '.$error_message;
        }else{
            return $response;
        }
    }

    private function post($url, $api_key = '', $body = '', $timeout = '')
    {
        $headers = $this->set_headers($api_key);

        $data = array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body
            );
        if($timeout){
            $data['timeout'] = $timeout;
        }
        
        $response = wp_remote_post( $url, $data );
        if(is_wp_error( $response )){
           $error_message = $response->get_error_message();
           echo __('Something went wrong', 'blockonomics-bitcoin-payments').': '.$error_message;
        }else{
            return $response;
        }
    }

    private function set_headers($api_key)
    {
        if($api_key){
            return 'Authorization: Bearer ' . $api_key;
        }else{
            return '';
        }
    }

    // Returns page endpoint of order adding the given extra parameters
    public function get_parameterized_url($type, $params = array())
    {
        if ($type === 'page') {
            $page_id = give_get_option('givewp_blockonomics_donation_page_id');
            $order_url = get_permalink( $page_id );
        }else{
            $order_url = admin_url('admin-ajax.php');
        }

        if (is_array($params) && count($params) > 0) {
            foreach ($params as $param_name => $param_value) {
                $order_url = add_query_arg($param_name, $param_value, $order_url);
            }
        }

        return $order_url;
    }

    // Returns url to redirect the user to during checkout
    public function get_order_checkout_url($order_id){
        $active_cryptos = $this->getActiveCurrencies();
        // Check if more than one crypto is activated
        $order_hash = $this->encrypt_hash($order_id);
        if (count($active_cryptos) > 1) {
            $order_url = $this->get_parameterized_url('page',array('select_crypto' => $order_hash));
        } elseif (count($active_cryptos) === 1) {
            $order_url = $this->get_parameterized_url('page',array('show_order' => $order_hash, 'crypto' => array_keys($active_cryptos)[0]));
        } else if (count($active_cryptos) === 0) {
            $order_url = $this->get_parameterized_url('page',array('crypto' => 'empty'));
        }
        return $order_url;
    }

    public function is_error_template($template_name) {
        if (strpos($template_name, 'error') === 0) {
            return true;
        }
        return false;
    }

    // Adds the style for blockonomics checkout page
    public function add_blockonomics_checkout_style($template_name, $additional_script=NULL){
        wp_enqueue_style( 'givewp-blockonomics-style' );
        if ($template_name === 'checkout') {
            add_action('wp_footer', function() use ($additional_script) {
                printf('<script type="text/javascript">%s</script>', $additional_script);
            });
            wp_enqueue_script( 'givewp-blockonomics-checkout' );
        }
    }

    public function set_template_context($context) {
        // Todo: With WP 5.5+, the load_template methods supports args
        // and can be used as a replacement to this.
        foreach ($context as $key => $value) {
            set_query_var($key, $value);
        }
    }

    // Adds the selected template to the blockonomics page
    public function load_blockonomics_template($template_name, $context = array(), $additional_script = NULL){
        $this->add_blockonomics_checkout_style($template_name, $additional_script);

        // Load the selected template
        $template = 'blockonomics_'.$template_name.'.php';
        // Load Template Context
        extract($context);
        // Load the checkout template
        ob_start(); // Start buffering
        include_once BLOCKONOMICS_GIVEWP_PLUGIN_DIR . "public/templates/" .$template;
        return ob_get_clean(); // Return the buffered content
    }

    public function calculate_order_params($order){
        // Check if order is unused or new
        if ( $order['payment_status'] == 0) {
            return $this->calculate_new_order_params($order);
        }
        return $order;
    }

    // Get order info for unused or new orders
    public function calculate_new_order_params($order){
        $donation = Donation::find($order['order_id']);
        global $wpdb;
        $order_id = $donation->id;
        $table_name = $wpdb->prefix .'givewp_blockonomics_payments'; 
        $query = $wpdb->prepare("SELECT expected_fiat,paid_fiat,currency FROM ". $table_name." WHERE order_id = %d " , $order_id);
        $results = $wpdb->get_results($query,ARRAY_A);
        $paid_fiat = $this->calculate_total_paid_fiat($results, $donation->amount->getCurrency()->getCode());
        $order['expected_fiat'] = $donation->amount->formatToDecimal() - $paid_fiat;
        $order['currency'] = $donation->amount->getCurrency()->getCode();
        if ($order['currency'] != 'BTC') {
            $responseObj = $this->get_price($order['currency'], $order['crypto']);
            if($responseObj->response_code != 200) {
                exit();
            }
            $price = $responseObj->price;
            $price = $price;
        } else {
            $price = 1;
        }
        $order['expected_satoshi'] = intval(round(1.0e8*$order['expected_fiat']/$price));
        return $order;
    }

    public function create_new_order($order_id, $crypto){
        $responseObj = $this->new_address(give_get_option("givewp_blockonomics_callback_secret"), $crypto);
        if($responseObj->response_code != 200) {
            return array("error"=>$responseObj->response_message);
        }
        $address = $responseObj->address;
        $order = array(
                'order_id'           => $order_id,
                'payment_status'     => 0,
                'crypto'             => $crypto,
                'address'            => $address
        );
        $order = $this->calculate_order_params($order);
        return $order;
    }

    public function get_error_context($error_type){
        $context = array();

        if ($error_type == 'generic') {
            // Show Generic Error to Client.
            $context['error_title'] = __('Could not generate new address (This may be a temporary error. Please try again)', 'blockonomics-bitcoin-payments');
            $context['error_msg'] = __('If this continues, please ask website administrator to do following:<br/><ul><li>Login to admin panel, navigate to Settings > Blockonomics > Currencies and click Test Setup to diagnose the exact issue.</li><li>Check blockonomics registered email address for error messages</li>', 'blockonomics-bitcoin-payments');
        } else if($error_type == 'underpaid') {
            $context['error_title'] = '';
            $context['error_msg'] = __('Paid order BTC amount is less than expected. Contact merchant', 'blockonomics-bitcoin-payments');
        }

        return $context;
    }

    public function fix_displaying_small_values($satoshi){
        return rtrim(number_format($satoshi/1.0e8, 8),0);
    }

    public function get_crypto_rate_from_params($value, $satoshi) {
        // Crypto Rate is re-calculated here and may slightly differ from the rate provided by Blockonomics
        // This is required to be recalculated as the rate is not stored anywhere in $order, only the converted satoshi amount is.
        // This method also helps in having a constant conversion and formatting for both JS and NoJS Templates avoiding the scientific notations.
        return number_format($value*1.0e8/$satoshi, 2, '.', '');
    }

    public function get_crypto_payment_uri($crypto, $address, $order_amount) {
        return $crypto['uri'] . ":" . $address . "?amount=" . $order_amount;
    }

    public function get_checkout_context($order, $crypto){
        
        $context = array();
        $error_context = NULL;

        $context['order_id'] = $order['order_id'];

        $cryptos = $this->getActiveCurrencies();
        $context['crypto'] = $cryptos[$crypto];

        if (array_key_exists('error', $order)) {
            $error_context = $this->get_error_context('generic');
        } else {
            $context['order'] = $order;

            if ($order['payment_status'] == 1 || ($order['payment_status'] == 2) ) {
                // Payment not confirmed i.e. payment in progress
                // Redirect to order received page- dont alllow new payment until existing payments are confirmed
                $this->redirect_finish_order();
            } else {
                // Display Checkout Page
                $context['order_amount'] = $this->fix_displaying_small_values($order['expected_satoshi']);
                // Payment URI is sent as part of context to provide initial Payment URI, this can be calculated using javascript
                // but we also need the URI for NoJS Templates and it makes sense to generate it from a single location to avoid redundancy!
                $context['payment_uri'] = $this->get_crypto_payment_uri($context['crypto'], $order['address'], $context['order_amount']);
                $context['crypto_rate_str'] = $this->get_crypto_rate_from_params($order['expected_fiat'], $order['expected_satoshi']);
                $context['total'] = $order['expected_fiat'];
                $paid_fiat = $this->get_order_paid_fiat($order['order_id']);

                if ($paid_fiat > 0) {
                    $context['paid_fiat'] = $paid_fiat;
                    $context['total'] = $order['expected_fiat'] + $context['paid_fiat'];
                }
            }
        }

        if ($error_context != NULL) {
            $context = array_merge($context, $error_context);
        }

        return $context;
    }


    public function get_checkout_template($context){
        if (array_key_exists('error_msg', $context)) {
            return 'error';
        } else {
            return 'checkout';
        }
    }

    public function get_checkout_script($context, $template_name) {
        $script = NULL;

        if ($template_name === 'checkout') {
            $order_hash = $this->encrypt_hash($context['order_id']);

            $script = "const blockonomics_data = '" . json_encode( array (
                'crypto' => $context['crypto'],
                'crypto_address' => $context['order']['address'],
                'time_period' => 10,
                'finish_order_url' => $this->get_received_url(),
                'get_order_amount_url' => $this->get_parameterized_url('api',array('get_amount'=>$order_hash, 'crypto'=>  $context['crypto']['code'])),
                'payment_uri' => $context['payment_uri']
            )). "'";
        }

        return $script;
    }

    // Load the the checkout template in the page
    public function load_checkout_template($order_id, $crypto){
        // Create or update the order
        $order = $this->process_order($order_id, $crypto);
        
        // Load Checkout Context
        $context = $this->get_checkout_context($order, $crypto);
        
        // Get Template to Load
        $template_name = $this->get_checkout_template($context);

        // Get any additional inline script to load
        $script = $this->get_checkout_script($context, $template_name);
        
        // Load the template
        return $this->load_blockonomics_template($template_name, $context, $script);
    }

    public function get_received_url(){
        return FormUtils::getSuccessPageURL();
    }

    // Redirect the user to the givewp finish order page
    public function redirect_finish_order(){
        echo '<a style="font-size: 0" id="link" href="'.$this->get_received_url().'" target="_parent"></a>
        <script>
            document.getElementById("link").click();
        </script>';
        exit();
    }

    // Fetch the correct crypto order linked to the order id
    public function get_order_by_id_and_crypto($order_id, $crypto){
        global $wpdb;
        $order = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "givewp_blockonomics_payments WHERE order_id = %d AND crypto = %s ORDER BY expected_satoshi ASC",
                $order_id,
                $crypto
            ),
            ARRAY_A
        );
        if($order){
            return $order[0];
        }
        return false;
    }


    // Inserts a new row in givewp_blockonomics_payments table
    public function insert_order($order){
        global $wpdb;
        $wpdb->hide_errors();
        $table_name = $wpdb->prefix . 'givewp_blockonomics_payments';
        return $wpdb->insert( 
            $table_name, 
            $order 
        );
    }

    // Updates an order in givewp_blockonomics_payments table
    public function update_order($order){
        global $wpdb;
        $table_name = $wpdb->prefix . 'givewp_blockonomics_payments';
        $wpdb->replace( 
            $table_name, 
            $order 
        );
    }

    // Check and update the crypto order or create a new order
    public function process_order($order_id, $crypto){
        $order = $this->get_order_by_id_and_crypto($order_id, $crypto);
        if ($order) {
            // Update the existing order info
            $order = $this->calculate_order_params($order);
            $this->update_order($order);
        }else {
            // Create and add the new order to the database
            $order = $this->create_new_order($order_id, $crypto);
            if (array_key_exists("error", $order)) {
                // Some error in Address Generation from API, return the same array.
                return $order;
            }
            $this->insert_order($order);
        }
        return $order;
    }

    // Get the order info by id and crypto
    public function get_order_amount_info($order_id, $crypto){
        $order = $this->process_order($order_id, $crypto);
        $order_amount = $this->fix_displaying_small_values($order['expected_satoshi']);        
        $cryptos = $this->getActiveCurrencies();
        $crypto_obj = $cryptos[$crypto];

        $response = array(
            "payment_uri" => $this->get_crypto_payment_uri($crypto_obj, $order['address'], $order_amount),
            "order_amount" => $order_amount,
            "crypto_rate_str" => $this->get_crypto_rate_from_params($order['expected_fiat'], $order['expected_satoshi'])
        );
        header("Content-Type: application/json");
        exit(json_encode($response));
    }

    // Get the order info by crypto address
    public function get_order_by_address($address){
        global $wpdb;
        $order = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."givewp_blockonomics_payments WHERE address = %s", array($address)),
            ARRAY_A
        );
        if($order){
            return $order;
        }
        exit(__("Error: Blockonomics order not found", 'blockonomics-bitcoin-payments'));
    }

    // Check if the callback secret in the request matches
    public function check_callback_secret($secret){
        $callback_secret = give_get_option("givewp_blockonomics_callback_secret");
        if ($callback_secret  && $callback_secret == $secret) {
            return true;
        }
        exit(__("Error: secret does not match", 'blockonomics-bitcoin-payments'));
    }

    public function update_paid_amount($callback_status, $paid_satoshi, $order, $donation){
        $network_confirmations = 2;
        if ($order['payment_status'] == 2) {
            return $order;
        }
        if ($callback_status >= $network_confirmations){
            $order['payment_status'] = 2;
            $order = $this->check_paid_amount($paid_satoshi, $order, $donation);
        } 
        else {
            // since $callback_status < $network_confirmations payment_status should be 1 i.e. payment in progress if payment is not already completed
            $order['payment_status'] = 1;
        }
        return $order;
    }

    // Check for underpayment, overpayment or correct amount
    public function check_paid_amount($paid_satoshi, $order, $donation){
        $order['paid_satoshi'] = $paid_satoshi;
        $paid_amount_ratio = $paid_satoshi/$order['expected_satoshi'];
        $order['paid_fiat'] =number_format($order['expected_fiat']*$paid_amount_ratio,2,'.','');

        // This is to update the order table before we send an email on failed and confirmed state
        // So that the updated data is used to build the email
        $this->update_order($order);

        if ($this->is_order_underpaid($order)) {
            $donation->status = DonationStatus::FAILED();
            $donation->gatewayTransactionId = $order['txid'];
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => 'Donation Underpaid for GiveWP Blockonomics Gateway.'
            ]);

        }
        else{
            $donation->status = DonationStatus::COMPLETE();
            $donation->gatewayTransactionId = $order['txid'];
            $donation->save();
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => 'Donation Completed from GiveWP Blockonomics Gateway.'
            ]);
        }
        return $order;
    }

    public function is_order_underpaid($order){
        // Return TRUE only if there has been a payment which is less than required.
        $is_order_underpaid = ($order['expected_satoshi'] > $order['paid_satoshi'] && !empty($order['paid_satoshi'])) ? TRUE : FALSE;
        return $is_order_underpaid;
    }

    // Process the blockonomics callback
    public function process_callback($secret, $address, $status, $value, $txid, $rbf){
        $this->check_callback_secret($secret);

        $order = $this->get_order_by_address($address);
        $donation = Donation::find($order['order_id']);

        if (empty($donation)) {
            exit(__("Error: GiveWP order not found", 'blockonomics-bitcoin-payments'));
        }
        
        $order['txid'] = $txid;

        if (!$rbf){
          // Unconfirmed RBF payments are easily cancelled should be ignored
          // https://insights.blockonomics.co/bitcoin-payments-can-now-easily-cancelled-a-step-forward-or-two-back/ 
          $order = $this->update_paid_amount($status, $value, $order, $donation);
        }

        $this->update_order($order);
    }

    /**
     * Encrypts a string using the application secret. This returns a hex representation of the binary cipher text
     *
     * @param  $input
     * @return string
     */
    public function encrypt_hash($input)
    {
        $encryption_algorithm = 'AES-128-CBC';
        $hashing_algorith = 'sha256';
        $secret = give_get_option('givewp_blockonomics_callback_secret');;
        $key = hash($hashing_algorith, $secret, true);
        $iv = substr($secret, 0, 16);

        $cipherText = openssl_encrypt(
            $input,
            $encryption_algorithm,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return bin2hex($cipherText);
    }

    /**
     * Decrypts a string using the application secret.
     *
     * @param  $hash
     * @return string
     */
    public function decrypt_hash($hash)
    {
        $encryption_algorithm = 'AES-128-CBC';
        $hashing_algorith = 'sha256';
        $secret = give_get_option('givewp_blockonomics_callback_secret');
        // prevent decrypt failing when $hash is not hex or has odd length
        if (strlen($hash) % 2 || !ctype_xdigit($hash)) {
            echo __("Error: Incorrect Hash. Hash cannot be validated.", 'blockonomics-bitcoin-payments');
            exit();
        }

        // we'll need the binary cipher
        $binaryInput = hex2bin($hash);
        $iv = substr($secret, 0, 16);
        $cipherText = $binaryInput;
        $key = hash($hashing_algorith, $secret, true);

        $decrypted = openssl_decrypt(
            $cipherText,
            $encryption_algorithm,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        $donation = Donation::find($decrypted);
        if (empty($donation)) {
            echo __("Error: Incorrect hash. GiveWP donation not found.", 'blockonomics-bitcoin-payments');
            exit();
        }

        return $decrypted;
    }
}