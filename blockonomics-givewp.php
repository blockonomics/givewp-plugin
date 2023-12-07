<?php
/**
 * Plugin Name: Bitcoin Donations - Blockonomics for GiveWP
 * Description: Bitcoin Donations for GiveWP by Blockonomics.
 * Version: 0.1.1
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: Blockonomics
 * Author URI: https://blockonomics.co
 * Text Domain: blockonomics-give
 * Domain Path: /languages
 */
use Give\Helpers\Form\Template\Utils\Frontend;

global $blockonomics_db_version;
$blockonomics_db_version = '1.0';
$plugin = plugin_basename(__FILE__);
define( 'BLOCKONOMICS_GIVEWP_PLUGIN_DIR', plugin_dir_path(__FILE__) );

// Register the blockonomics gateway
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include_once 'includes/class-blockonomics-gateway.php';
    $paymentGatewayRegister->registerGateway(GiveWpBlockonomicsPaymentGateway::class);
});
register_activation_hook(__FILE__, 'givewp_blockonomics_activation_hook');
register_activation_hook(__FILE__, 'givewp_blockonomics_plugin_setup');
register_deactivation_hook(__FILE__, 'givewp_blockonomics_deactivation_hook');
add_action('plugins_loaded', 'givewp_blockonomics_update_db_check');
add_action('rest_api_init', 'givewp_blockonomics_callback_url_endpoint');
add_action('wp_enqueue_scripts', 'givewp_blockonomics_register_stylesheets');
add_action('wp_enqueue_scripts', 'givewp_blockonomics_register_scripts');
add_action('wp_ajax_nopriv_get_amount', 'givewp_blockonomics_ajax_handler');
add_action('wp_ajax_get_amount', 'givewp_blockonomics_ajax_handler');
add_shortcode('blockonomics_donation', 'givewp_blockonomics_add_donation_page_shortcode');
add_filter("plugin_action_links_$plugin", 'givewp_blockonomics_plugin_add_settings_link');
add_filter('give_get_sections_gateways', 'blockonomics_for_give_register_payment_gateway_sections');
add_filter('give_get_settings_gateways', 'givewp_blockonomics_register_payment_gateway_setting_fields');

function givewp_blockonomics_create_table()
{
    // Create givewp_blockonomics_payments table
    // https://codex.wordpress.org/Creating_Tables_with_Plugins
    global $wpdb;
    global $blockonomics_db_version;

    $table_name = $wpdb->prefix . 'givewp_blockonomics_payments';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        order_id int NOT NULL,
        payment_status int NOT NULL,
        crypto varchar(3) NOT NULL,
        address varchar(191) NOT NULL,
        expected_satoshi bigint,
        expected_fiat double,
        currency varchar(3),
        paid_satoshi bigint,
        paid_fiat double,
        txid text,
        PRIMARY KEY  (address),
        KEY orderkey (order_id,crypto)
    ) $charset_collate;";
    dbDelta($sql);

    update_option('givewp_blockonomics_db_version', $blockonomics_db_version);
}

function givewp_blockonomics_activation_hook()
{
    if(!is_plugin_active('give/give.php')) {
        trigger_error(__('GiveWP Bitcoin Payments - Blockonomics requires GiveWP plugin to be installed and active.', 'givewp-blockonomics') . '<br>', E_USER_ERROR);
    }

    set_transient('givewp_blockonomics_activation_hook_transient', true, 3);
}

// Page creation function  for the Blockonomics payement following woo-commerce page creation shortcode logic
function givewp_blockonomics_create_payment_page()
{
    $page_id = wp_insert_post(
        [
            'post_title' => esc_html__('Crypto Donation', 'give'),
            'post_name' => 'crypto_donation',
            'post_content' => '[blockonomics_donation]',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
            'comment_status' => 'closed',
        ]
    );

    give_update_option('givewp_blockonomics_donation_page_id', $page_id);
}

function givewp_blockonomics_update_db_check()
{
    global $blockonomics_db_version;
    $installed_ver = get_site_option('givewp_blockonomics_db_version');
    if (empty($installed_ver)) {
        givewp_blockonomics_plugin_setup();
    }
}

function givewp_blockonomics_plugin_setup()
{
    givewp_blockonomics_create_table();
    givewp_blockonomics_create_payment_page();
    givewp_blockonomics_generate_secret();
}

function givewp_blockonomics_deactivation_hook()
{
    // Remove the custom page and shortcode added for payment
    remove_shortcode('blockonomics_donation');
    wp_delete_post(give_get_option('givewp_blockonomics_donation_page_id'), true);
    give_delete_option('givewp_blockonomics_donation_page_id');
    give_delete_option('givewp_blockonomics_callback_secret');
    give_delete_option('givewp_blockonomics_callback_url');
    give_delete_option('givewp_blockonomics_api_key');
    give_delete_option('givewp_blockonomics_btc');
}

function givewp_blockonomics_plugin_add_settings_link($links)
{
    $settings_link = '<a href="edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=blockonomics-settings">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function givewp_blockonomics_add_donation_page_shortcode()
{
    // Temp Fix for givewp referer check, which leads to blank checkout page during on-site redirect
    global $wp;
    $current_url = home_url(add_query_arg($_GET, $wp->request));
    $base = Give()->routeForm->getBase();
    $formName = get_post_field('post_name', Frontend::getFormId());
    $referer = trailingslashit(wp_get_referer()) ?: '';
    if (false !== strpos($referer, "/{$base}/{$formName}/")) {
        return '<a style="font-size: 0" id="link" href="' . $current_url . '" target="_parent"></a>
        <script>
            document.getElementById("link").click();
        </script>';
    }

    // This is to make sure we only run the shortcode when executed to render the page.
    // Because the shortcode can be run multiple times by other plugin like All in One SEO.
    // Where it tries to build SEO content from the shortcode and this could lead to checkout page not loading correctly.
    $currentFilter = current_filter();
    if ($currentFilter == 'wp_head') {
        return;
    }

    $show_order = isset($_GET["show_order"]) ? sanitize_text_field(wp_unslash($_GET['show_order'])) : "";
    $crypto = isset($_GET["crypto"]) ? sanitize_key($_GET['crypto']) : "";
    $select_crypto = isset($_GET["select_crypto"]) ? sanitize_text_field(wp_unslash($_GET['select_crypto'])) : "";

    include_once 'includes/blockonomics.php';
    $blockonomics = new GiveWpBlockonomics();

    if ($crypto === "empty") {
        return $blockonomics->load_blockonomics_template('no_crypto_selected');
    } elseif ($show_order && $crypto) {
        $order_id = $blockonomics->decrypt_hash($show_order);
        return $blockonomics->load_checkout_template($order_id, $crypto);
    } elseif ($select_crypto) {
        return $blockonomics->load_blockonomics_template('crypto_options');
    }
}

function givewp_blockonomics_register_stylesheets()
{
    wp_register_style('givewp-blockonomics-style', plugin_dir_url(__FILE__) . "public/css/order.css", '', get_plugin_data(__FILE__)['Version']);
}

function givewp_blockonomics_register_scripts()
{
    wp_register_script('givewp-blockonomics-reconnecting-websocket', plugins_url('public/js/vendors/reconnecting-websocket.min.js#deferload', __FILE__), array(), get_plugin_data(__FILE__)['Version']);
    wp_register_script('givewp-blockonomics-qrious', plugins_url('public/js/vendors/qrious.min.js#deferload', __FILE__), array(), get_plugin_data(__FILE__)['Version']);
    wp_register_script('givewp-blockonomics-copytoclipboard', plugins_url('public/js/vendors/copytoclipboard.js#deferload', __FILE__), array(), get_plugin_data(__FILE__)['Version']);
    wp_register_script('givewp-blockonomics-checkout', plugins_url('public/js/checkout.js#deferload', __FILE__), array('givewp-blockonomics-reconnecting-websocket', 'givewp-blockonomics-qrious','givewp-blockonomics-copytoclipboard'), get_plugin_data(__FILE__)['Version'], array('in_footer' => true ));
}

function givewp_blockonomics_ajax_handler()
{
    $crypto = isset($_GET["crypto"]) ? sanitize_key($_GET['crypto']) : "";
    $get_amount = isset($_GET['get_amount']) ? sanitize_text_field(wp_unslash($_GET['get_amount'])) : "";

    include_once 'includes/blockonomics.php';
    $blockonomics = new GiveWpBlockonomics();

    if ($get_amount && $crypto) {
        $order_id = $blockonomics->decrypt_hash($get_amount);
        $blockonomics->get_order_amount_info($order_id, $crypto);
    }

    wp_die();
}

function givewp_blockonomics_generate_secret($force_generate = false)
{
    $callback_secret = give_get_option('givewp_blockonomics_callback_secret');
    if (!$callback_secret || $force_generate) {
        $callback_secret = sha1(openssl_random_pseudo_bytes(20));
        give_update_option("givewp_blockonomics_callback_secret", $callback_secret);
    }
}

function givewp_blockonomics_get_callback_url()
{
    $callback_url = get_site_url() . "/wp-json/blockonomics-give/callback";
    $callback_secret = give_get_option('givewp_blockonomics_callback_secret');
    $callback_url = add_query_arg('secret', $callback_secret, $callback_url);
    return $callback_url;
}

function givewp_blockonomics_register_payment_gateway_setting_fields($settings)
{
    switch(give_get_current_setting_section()) {
        case 'blockonomics-settings':
            give_update_option('givewp_blockonomics_callback_url', givewp_blockonomics_get_callback_url());

            $settings = array(
                array(
                    'id'	=> 'give_title_blockonomics',
                    'type'	=> 'title'
                ),
            );

            $settings[] = array(
                'name'	=> __('API Key', 'blockonomics-api-key'),
                'desc'	=> __('Enter your API Key, found in your blockonomics profile', 'blockonomics-for-give'),
                'id'	=> 'givewp_blockonomics_api_key',
                'type'	=> 'text',
            );

            $settings[] = array(
                'name'	=> __('Callback URL'),
                'desc'	=> __('Callback URL for your store', 'blockonomics-for-give'),
                'id'	=> 'givewp_blockonomics_callback_url',
                'type'	=> 'text',
                'attributes' => array(
                    'disabled'	=> 'disabled'
                ),
            );

            $settings[] = array(
                'id'	=> 'give_title_blockonomics',
                'type'	=> 'sectionend',
            );

            break;
    }

    return $settings;
}

function blockonomics_for_give_register_payment_gateway_sections($sections)
{
    $sections['blockonomics-settings'] = __('Blockonomics', 'blockonomics-for-give');
    return $sections;
}

function givewp_blockonomics_callback_url_endpoint() {
	register_rest_route(
		'blockonomics-give',
		'callback',
		array(
			'methods' => 'GET',
			'callback' => 'givewp_blockonomics_handle_callback',
            'permission_callback' => '__return_true'
		)
	);
}

function givewp_blockonomics_handle_callback($request_data) {
	$secret = isset($_GET['secret']) ? sanitize_text_field(wp_unslash($_GET['secret'])) : "";
    $addr = isset($_GET['addr']) ? sanitize_text_field(wp_unslash($_GET['addr'])) : "";
    $status = isset($_GET['status']) ? intval($_GET['status']) : "";
    $value = isset($_GET['value']) ? absint($_GET['value']) : "";
    $txid = isset($_GET['txid']) ? sanitize_text_field(wp_unslash($_GET['txid'])) : "";
    $rbf = isset($_GET['rbf']) ? wp_validate_boolean(intval(wp_unslash($_GET['rbf']))) : "";

    include_once 'includes/blockonomics.php';
    $blockonomics = new GiveWpBlockonomics();

    if ($secret && $addr && isset($status) && $value && $txid) {
        $blockonomics->process_callback($secret, $addr, $status, $value, $txid, $rbf);
    }

	die();
}