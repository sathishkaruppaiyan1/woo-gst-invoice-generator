<?php
/**
 * WC_GST_Invoice_Bulk_Download Class
 *
 * Handles bulk invoice downloads with date range selection
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_Bulk_Download {

    /**
     * Constructor
     */
    public function __construct() {
        // Add bulk download page
        add_action('admin_menu', array($this, 'add_bulk_download_page'));
        
        // Handle bulk download form submission
        add_action('admin_post_wgig_bulk_download', array($this, 'handle_bulk_download'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Add bulk download page to admin menu
     */
    public function add_bulk_download_page() {
        add_submenu_page(
            'wgig-settings',
            __('Bulk Download Invoices', 'woo-gst-invoice'),
            __('Bulk Download', 'woo-gst-invoice'),
            'manage_options',
            'wgig-bulk-download',
            array($this, 'render_bulk_download_page')
        );
    }

    /**
     * Render bulk download page
     */
    public function render_bulk_download_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Bulk Download GST Invoices', 'woo-gst-invoice'); ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="wgig-bulk-download-form">
                <input type="hidden" name="action" value="wgig_bulk_download">
                <?php wp_nonce_field('wgig_bulk_download', 'wgig_bulk_download_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="start_date"><?php _e('Start Date', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <input type="date" name="start_date" id="start_date" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="end_date"><?php _e('End Date', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <input type="date" name="end_date" id="end_date" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="order_status"><?php _e('Order Status', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <select name="order_status" id="order_status" class="regular-text">
                                <option value=""><?php _e('All Statuses', 'woo-gst-invoice'); ?></option>
                                <?php
                                $statuses = wc_get_order_statuses();
                                foreach ($statuses as $status => $label) {
                                    echo '<option value="' . esc_attr($status) . '">' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Download Invoices', 'woo-gst-invoice')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle bulk download
     */
    public function handle_bulk_download() {
        if (!isset($_POST['wgig_bulk_download_nonce']) || !wp_verify_nonce($_POST['wgig_bulk_download_nonce'], 'wgig_bulk_download')) {
            wp_die(__('Invalid request', 'woo-gst-invoice'));
        }

        // Add enhanced debugging
        error_log('Bulk Download Debug: Starting bulk download process');
        
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $order_status = isset($_POST['order_status']) ? sanitize_text_field($_POST['order_status']) : '';
        
        error_log('Order status filter: ' . $order_status);

        // Debug information
        error_log('Start Date: ' . $start_date);
        error_log('End Date: ' . $end_date);

        if (empty($start_date) || empty($end_date)) {
            wp_die(__('Please select a date range', 'woo-gst-invoice'));
        }

        // Convert dates to timestamps
        $start_timestamp = strtotime($start_date . ' 00:00:00');
        $end_timestamp = strtotime($end_date . ' 23:59:59');

        // Debug timestamps
        error_log('Start Timestamp: ' . $start_timestamp);
        error_log('End Timestamp: ' . $end_timestamp);

        // Get all orders in the date range
        $args = array(
            'limit' => -1,
            'date_created' => $start_timestamp . '...' . $end_timestamp,
        );
        
        // Add status filter if specified
        if (!empty($order_status)) {
            $args['status'] = $order_status;
        }

        $all_orders = wc_get_orders($args);
        error_log('Total orders in date range: ' . count($all_orders));

        // Log all orders for debugging
        foreach ($all_orders as $index => $order) {
            error_log('Order ' . ($index + 1) . ': ID ' . $order->get_id() . ', Status: ' . $order->get_status());
        }

        if (empty($all_orders)) {
            wp_die(__('No orders found in the selected date range.', 'woo-gst-invoice'));
        }

        // Initialize invoice generator
        $invoice_generator = new WC_GST_Invoice_Generator();
        $orders_with_invoices = array();
        $failed_orders = array();

        // Process each order
        foreach ($all_orders as $order) {
            try {
                $order_id = $order->get_id();
                $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
                
                // If no invoice number exists, generate one
                if (!$invoice_number) {
                    $invoice_number = $invoice_generator->generate_invoice($order_id);
                    error_log('Generated invoice for order ' . $order_id . ': ' . $invoice_number);
                } else {
                    error_log('Using existing invoice for order ' . $order_id . ': ' . $invoice_number);
                }
                
                // Always add order to the list if we have or just generated an invoice number
                if ($invoice_number) {
                    $orders_with_invoices[] = $order;
                    error_log('Added order ' . $order_id . ' to processing list');
                } else {
                    error_log('Failed to generate invoice for order ' . $order_id);
                    $failed_orders[] = $order_id;
                }
            } catch (Exception $e) {
                error_log('Error processing order ' . $order_id . ': ' . $e->getMessage());
                $failed_orders[] = $order_id;
                continue;
            }
        }

        error_log('Orders with invoices: ' . count($orders_with_invoices));
        error_log('Failed orders: ' . count($failed_orders));

        if (empty($orders_with_invoices)) {
            $error_message = __('No orders with invoices found in the selected date range.', 'woo-gst-invoice');
            if (!empty($failed_orders)) {
                $error_message .= ' ' . sprintf(__('Failed orders: %s', 'woo-gst-invoice'), implode(', ', $failed_orders));
            }
            wp_die($error_message);
        }

        // Initialize PDF generator
        $pdf_generator = new WC_GST_Invoice_PDF();

        try {
            // Create a PDF with the first order
            error_log('Creating PDF with first order: ' . $orders_with_invoices[0]->get_id());
            $pdf = $pdf_generator->create_pdf($orders_with_invoices[0]->get_id());
            
            // Add remaining orders as new pages
            for ($i = 1; $i < count($orders_with_invoices); $i++) {
                try {
                    $pdf->AddPage();
                    error_log('Adding order to PDF: ' . $orders_with_invoices[$i]->get_id());
                    $pdf_generator->add_order_to_pdf($pdf, $orders_with_invoices[$i]->get_id());
                } catch (Exception $e) {
                    error_log('Error adding order ' . $orders_with_invoices[$i]->get_id() . ' to PDF: ' . $e->getMessage());
                    $failed_orders[] = $orders_with_invoices[$i]->get_id();
                    continue;
                }
            }

            // Make sure we clear any previous output before sending the PDF
            if (ob_get_contents()) ob_end_clean();
            
            // Set headers for download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="gst-invoices-' . date('Y-m-d') . '.pdf"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            error_log('Sending PDF output');
            // Output PDF - use 'I' to send to browser
            $pdf->Output('gst-invoices-' . date('Y-m-d') . '.pdf', 'I');
            exit;
        } catch (Exception $e) {
            error_log('Error generating final PDF: ' . $e->getMessage());
            $error_message = sprintf(__('Error generating PDF: %s', 'woo-gst-invoice'), $e->getMessage());
            if (!empty($failed_orders)) {
                $error_message .= ' ' . sprintf(__('Failed orders: %s', 'woo-gst-invoice'), implode(', ', $failed_orders));
            }
            wp_die($error_message, __('Error', 'woo-gst-invoice'), array('response' => 500, 'back_link' => true));
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook === 'gst-invoice_page_wgig-bulk-download') {
            wp_enqueue_style('wgig-admin-css', WGIG_PLUGIN_URL . 'assets/css/admin.css', array(), WGIG_VERSION);
            wp_enqueue_script('wgig-admin-js', WGIG_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WGIG_VERSION, true);
        }
    }
}