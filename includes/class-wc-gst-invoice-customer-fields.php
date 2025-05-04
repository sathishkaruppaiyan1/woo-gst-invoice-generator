<?php
/**
 * WC_GST_Invoice_Customer_Fields Class
 *
 * Adds GST-related fields to WooCommerce checkout
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_Customer_Fields {

    /**
     * Constructor
     */
    public function __construct() {
        // Add GST fields to checkout
        add_filter('woocommerce_checkout_fields', array($this, 'add_gst_billing_fields'));
        
        // Add GST fields to order details
        add_filter('woocommerce_admin_billing_fields', array($this, 'add_gst_fields_to_order_details'));
        
        // Add GST fields to order edit page
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_gst_fields_in_order_edit'));
        
        // Add GST fields to order details page
        add_action('woocommerce_order_details_after_customer_details', array($this, 'display_gst_fields_in_order_details'));
        
        // Remove state code and GSTIN when order status changes to processing
        add_action('woocommerce_order_status_changed', array($this, 'remove_fields_on_processing'), 10, 3);
    }

    /**
     * Add GST fields to billing fields
     *
     * @param array $fields Billing fields
     * @return array Modified billing fields
     */
    public function add_gst_billing_fields($fields) {
        $fields['billing']['billing_gstin'] = array(
            'label'       => __('GSTIN', 'woo-gst-invoice'),
            'placeholder' => __('GSTIN (if registered)', 'woo-gst-invoice'),
            'required'    => false,
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'priority'    => 120
        );
        
        $fields['billing']['billing_state_code'] = array(
            'label'       => __('State Code', 'woo-gst-invoice'),
            'placeholder' => __('State Code (e.g. 33)', 'woo-gst-invoice'),
            'required'    => false,
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'priority'    => 121
        );
        
        return $fields;
    }

    /**
     * Save GST fields to order
     *
     * @param int $order_id Order ID
     */
    public function save_gst_checkout_fields($order_id) {
        if (!empty($_POST['billing_gstin'])) {
            update_post_meta($order_id, '_billing_gstin', sanitize_text_field($_POST['billing_gstin']));
        }
        
        if (!empty($_POST['billing_state_code'])) {
            update_post_meta($order_id, '_billing_state_code', sanitize_text_field($_POST['billing_state_code']));
        } else {
            // Try to get state code from state
            $order = wc_get_order($order_id);
            $billing_state = $order->get_billing_state();
            
            // State code mapping for Indian states
            $state_codes = array(
                'AN' => '35', // Andaman and Nicobar Islands
                'AP' => '28', // Andhra Pradesh
                'AR' => '12', // Arunachal Pradesh
                'AS' => '18', // Assam
                'BR' => '10', // Bihar
                'CH' => '04', // Chandigarh
                'CT' => '22', // Chhattisgarh
                'DD' => '26', // Daman and Diu
                'DH' => '26', // Dadra and Nagar Haveli
                'DL' => '07', // Delhi
                'GA' => '30', // Goa
                'GJ' => '24', // Gujarat
                'HP' => '02', // Himachal Pradesh
                'HR' => '06', // Haryana
                'JH' => '20', // Jharkhand
                'JK' => '01', // Jammu and Kashmir
                'KA' => '29', // Karnataka
                'KL' => '32', // Kerala
                'LA' => '38', // Ladakh
                'LD' => '31', // Lakshadweep
                'MH' => '27', // Maharashtra
                'ML' => '17', // Meghalaya
                'MN' => '14', // Manipur
                'MP' => '23', // Madhya Pradesh
                'MZ' => '15', // Mizoram
                'NL' => '13', // Nagaland
                'OR' => '21', // Odisha
                'PB' => '03', // Punjab
                'PY' => '34', // Puducherry
                'RJ' => '08', // Rajasthan
                'SK' => '11', // Sikkim
                'TG' => '36', // Telangana
                'TN' => '33', // Tamil Nadu
                'TR' => '16', // Tripura
                'UP' => '09', // Uttar Pradesh
                'UT' => '05', // Uttarakhand
                'WB' => '19', // West Bengal
            );
            
            if (!empty($billing_state) && isset($state_codes[$billing_state])) {
                update_post_meta($order_id, '_billing_state_code', $state_codes[$billing_state]);
            }
        }
    }

    /**
     * Display GST fields in admin order details
     *
     * @param WC_Order $order Order object
     */
    public function display_gst_fields_in_admin_order($order) {
        $gstin = get_post_meta($order->get_id(), '_billing_gstin', true);
        $state_code = get_post_meta($order->get_id(), '_billing_state_code', true);
        
        if (!empty($gstin) || !empty($state_code)) {
            echo '<p><strong>' . __('GSTIN:', 'woo-gst-invoice') . '</strong> ' . esc_html($gstin) . '</p>';
            echo '<p><strong>' . __('State Code:', 'woo-gst-invoice') . '</strong> ' . esc_html($state_code) . '</p>';
        }
    }

    /**
     * Display GST fields in order emails
     *
     * @param WC_Order $order Order object
     * @param bool $sent_to_admin Whether the email is sent to admin
     * @param bool $plain_text Whether the email is plain text
     * @param WC_Email $email Email object
     */
    public function display_gst_fields_in_emails($order, $sent_to_admin, $plain_text, $email) {
        $gstin = get_post_meta($order->get_id(), '_billing_gstin', true);
        $state_code = get_post_meta($order->get_id(), '_billing_state_code', true);
        
        if (empty($gstin) && empty($state_code)) {
            return;
        }
        
        if ($plain_text) {
            echo "\n" . __('GSTIN:', 'woo-gst-invoice') . ' ' . $gstin . "\n";
            echo __('State Code:', 'woo-gst-invoice') . ' ' . $state_code . "\n";
        } else {
            echo '<div class="address"><h3>' . __('GST Information', 'woo-gst-invoice') . '</h3>';
            echo '<p><strong>' . __('GSTIN:', 'woo-gst-invoice') . '</strong> ' . esc_html($gstin) . '</p>';
            echo '<p><strong>' . __('State Code:', 'woo-gst-invoice') . '</strong> ' . esc_html($state_code) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Display GST fields in order details page
     *
     * @param WC_Order $order Order object
     */
    public function display_gst_fields_in_order_details($order) {
        $gstin = get_post_meta($order->get_id(), '_billing_gstin', true);
        $state_code = get_post_meta($order->get_id(), '_billing_state_code', true);
        
        if (empty($gstin) && empty($state_code)) {
            return;
        }
        
        echo '<section class="woocommerce-customer-details gst-details">';
        echo '<h2 class="woocommerce-column__title">' . __('GST Information', 'woo-gst-invoice') . '</h2>';
        echo '<p><strong>' . __('GSTIN:', 'woo-gst-invoice') . '</strong> ' . esc_html($gstin) . '</p>';
        echo '<p><strong>' . __('State Code:', 'woo-gst-invoice') . '</strong> ' . esc_html($state_code) . '</p>';
        echo '</section>';
    }

    /**
     * Remove state code and GSTIN when order status changes to processing
     */
    public function remove_fields_on_processing($order_id, $old_status, $new_status) {
        if ($new_status === 'processing') {
            delete_post_meta($order_id, '_billing_state_code');
            delete_post_meta($order_id, '_billing_gstin');
        }
    }

    /**
     * Add GST fields to order details
     */
    public function add_gst_fields_to_order_details($fields) {
        // Remove state code and GSTIN fields from order details
        unset($fields['billing_state_code']);
        unset($fields['billing_gstin']);
        
        return $fields;
    }

    /**
     * Display GST fields in order edit page
     */
    public function display_gst_fields_in_order_edit($order) {
        $order_id = $order->get_id();
        ?>
        <div class="address">
            <p>
                <strong><?php _e('GSTIN:', 'woo-gst-invoice'); ?></strong>
                <input type="text" name="_billing_gstin" value="<?php echo esc_attr(get_post_meta($order_id, '_billing_gstin', true)); ?>" class="regular-text">
            </p>
            <p>
                <strong><?php _e('State Code:', 'woo-gst-invoice'); ?></strong>
                <input type="text" name="_billing_state_code" value="<?php echo esc_attr(get_post_meta($order_id, '_billing_state_code', true)); ?>" class="regular-text">
            </p>
        </div>
        <?php
    }
}

// Initialize the class
new WC_GST_Invoice_Customer_Fields();