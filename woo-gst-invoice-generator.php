  <?php
/**
 * Plugin Name: WooCommerce GST Invoice Generator
 * Plugin URI: 
 * Description: Generate GST invoices for WooCommerce orders with custom template
 * Version: 1.0.3
 * Author: sathishkaruppaiyan
 * Author URI: sathishkaruppaiyan.in
 * Text Domain: woo-gst-invoice
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WGIG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WGIG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WGIG_VERSION', '1.0.2');

/**
 * Check if WooCommerce is active
 */
function wgig_check_woocommerce_active() {
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) && 
        !array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) {
        add_action('admin_notices', 'wgig_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display WooCommerce missing notice
 */
function wgig_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce GST Invoice Generator requires WooCommerce to be installed and active.', 'woo-gst-invoice'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function wgig_init() {
    if (!wgig_check_woocommerce_active()) {
        return;
    }
    
    // Load text domain
    load_plugin_textdomain('woo-gst-invoice', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Include required files
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-admin.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-generator.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-pdf.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-product-fields.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-customer-fields.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-bulk-download.php';
    require_once WGIG_PLUGIN_DIR . 'includes/class-wc-gst-invoice-tax-export.php';
    
    // Initialize classes
    new WC_GST_Invoice_Admin();
    new WC_GST_Invoice_Generator();
    new WC_GST_Invoice_Customer_Fields();
    new WC_GST_Invoice_Product_Fields();
    new WC_GST_Invoice_Bulk_Download();
    
    // Add download invoice AJAX handler
    add_action('wp_ajax_wgig_download_invoice', 'wgig_download_invoice_handler');
    add_action('wp_ajax_wgig_bulk_download_invoices', 'wgig_bulk_download_invoices_handler');
    
    // Add direct generate invoice handler for row actions
    add_action('admin_post_wgig_generate_invoice', 'wgig_generate_invoice_handler');
    
    // Add My Account invoice download functionality for customers
    add_filter('woocommerce_my_account_my_orders_actions', 'wgig_add_my_account_invoice_action', 10, 2);
}
add_action('plugins_loaded', 'wgig_init');

/**
 * Handle generate invoice requests
 */
function wgig_generate_invoice_handler() {
    if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woo-gst-invoice'));
    }
    
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'wgig_generate_invoice')) {
        wp_die(__('Security check failed.', 'woo-gst-invoice'));
    }
    
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        wp_die(__('No order specified.', 'woo-gst-invoice'));
    }
    
    $invoice_generator = new WC_GST_Invoice_Generator();
    $invoice_number = $invoice_generator->generate_invoice($order_id);
    
    if (!$invoice_number) {
        wp_die(__('Failed to generate invoice.', 'woo-gst-invoice'));
    }
    
    // Redirect back to the orders page
    wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('edit.php?post_type=shop_order'));
    exit;
}

/**
 * Handle invoice download requests
 */
function wgig_download_invoice_handler() {
    // Verify if user has permission (admin or customer viewing own order)
    if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
        // Check if this is a customer viewing their own order
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'woo-gst-invoice'));
        }
        
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if (!$order_id) {
            wp_die(__('No order specified.', 'woo-gst-invoice'));
        }
        
        $order = wc_get_order($order_id);
        if (!$order || $order->get_customer_id() != $user_id) {
            wp_die(__('You do not have permission to download this invoice.', 'woo-gst-invoice'));
        }
    }
    
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'wgig_download_invoice')) {
        wp_die(__('Security check failed.', 'woo-gst-invoice'));
    }
    
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
        wp_die(__('No order specified.', 'woo-gst-invoice'));
    }
    
    $invoice_generator = new WC_GST_Invoice_Generator();
    $invoice_number = $invoice_generator->generate_invoice($order_id);
    
    if (!$invoice_number) {
        wp_die(__('Failed to generate invoice.', 'woo-gst-invoice'));
    }
    
    $pdf_generator = new WC_GST_Invoice_PDF();
    $pdf_generator->generate_and_download_pdf($order_id);
    
    exit;
}

/**
 * Handle bulk invoice downloads
 */
function wgig_bulk_download_invoices_handler() {
    if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'woo-gst-invoice'));
    }
    
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'wgig_bulk_download_invoices')) {
        wp_die(__('Security check failed.', 'woo-gst-invoice'));
    }
    
    $order_ids = isset($_GET['order_ids']) ? explode(',', sanitize_text_field($_GET['order_ids'])) : array();
    if (empty($order_ids)) {
        wp_die(__('No orders specified.', 'woo-gst-invoice'));
    }
    
    $invoice_generator = new WC_GST_Invoice_Generator();
    $pdf_generator = new WC_GST_Invoice_PDF();
    
    // If only one order, generate single PDF
    if (count($order_ids) === 1) {
        $order_id = $order_ids[0];
        $invoice_number = $invoice_generator->generate_invoice($order_id);
        if (!$invoice_number) {
            wp_die(__('Failed to generate invoice.', 'woo-gst-invoice'));
        }
        $pdf_generator->generate_and_download_pdf($order_id);
    } else {
        // If multiple orders, create ZIP archive
        $pdf_generator->generate_and_download_multiple_pdfs($order_ids);
    }
    
    exit;
}

/**
 * Add download invoice action to My Account orders
 */
function wgig_add_my_account_invoice_action($actions, $order) {
    // Only add for completed or processing orders
    if ($order->has_status(array('completed', 'processing'))) {
        $order_id = $order->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        // Check if auto-generation is enabled for customers
        $auto_generate = get_option('wgig_auto_generate_customer', 'yes') === 'yes';
        
        // Only add if invoice exists or auto-generate is enabled
        if ($invoice_number || $auto_generate) {
            // Generate invoice if needed and auto-generate is enabled
            if (!$invoice_number && $auto_generate) {
                $invoice_generator = new WC_GST_Invoice_Generator();
                $invoice_number = $invoice_generator->generate_invoice($order_id);
            }
            
            if ($invoice_number) {
                $actions['wgig_download_invoice'] = array(
                    'url'  => wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'   => 'wgig_download_invoice',
                                'order_id' => $order_id
                            ),
                            admin_url('admin-ajax.php')
                        ),
                        'wgig_download_invoice',
                        'nonce'
                    ),
                    'name' => sprintf(
                        __('Download Invoice %s', 'woo-gst-invoice'),
                        $invoice_number
                    )
                );
            }
        }
    }
    
    return $actions;
}

/**
 * Plugin activation hook
 */
function wgig_activate() {
    // Create necessary folders
    $upload_dir = wp_upload_dir();
    $invoice_dir = $upload_dir['basedir'] . '/wgig-invoices';
    
    if (!file_exists($invoice_dir)) {
        wp_mkdir_p($invoice_dir);
    }
    
    // Add a .htaccess file to protect the invoices
    $htaccess_file = $invoice_dir . '/.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "deny from all\n";
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    // Set default options
    if (!get_option('wgig_next_invoice_number')) {
        update_option('wgig_next_invoice_number', 1);
    }
    
    if (!get_option('wgig_declaration_text')) {
        update_option('wgig_declaration_text', 'We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.');
    }
    
    // Option for auto-generating invoices for customers
    if (!get_option('wgig_auto_generate_customer')) {
        update_option('wgig_auto_generate_customer', 'yes');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'wgig_activate');

/**
 * Plugin deactivation hook
 */
function wgig_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'wgig_deactivate');