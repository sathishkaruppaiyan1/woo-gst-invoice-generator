<?php
/**
 * WC_GST_Invoice_Tax_Export Class
 *
 * Handles tax export functionality for WooCommerce GST Invoice Generator
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * WooCommerce Tax Export Main Class
 */
class WC_GST_Invoice_Tax_Export {

    /**
     * Constructor
     */
    public function __construct() {
        // Add menu item
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        
        // Register hook for processing the export
        add_action('admin_init', array($this, 'process_export'));
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wgig-settings',
            __('Tax Export', 'woo-gst-invoice'),
            __('Tax Export', 'woo-gst-invoice'),
            'manage_woocommerce',
            'wgig-tax-export',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin page content
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('GST Tax Export', 'woo-gst-invoice'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wgig_tax_export_nonce', 'wgig_tax_export_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="start_date"><?php _e('Start Date', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="end_date"><?php _e('End Date', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="export_type"><?php _e('Export Type', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <select id="export_type" name="export_type">
                                <option value="summary"><?php _e('Tax Summary', 'woo-gst-invoice'); ?></option>
                                <option value="detailed"><?php _e('Detailed Tax Report', 'woo-gst-invoice'); ?></option>
                                <option value="by_tax_rate"><?php _e('By Tax Rate', 'woo-gst-invoice'); ?></option>
                                <option value="gst_summary"><?php _e('GST Summary', 'woo-gst-invoice'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="order_status"><?php _e('Order Status', 'woo-gst-invoice'); ?></label>
                        </th>
                        <td>
                            <select id="order_status" name="order_status[]" multiple style="width: 300px; height: 100px;">
                                <?php
                                $order_statuses = wc_get_order_statuses();
                                foreach ($order_statuses as $status => $label) {
                                    $status_name = 'wc-' === substr($status, 0, 3) ? substr($status, 3) : $status;
                                    echo '<option value="' . esc_attr($status_name) . '" '
                                        . selected($status_name == 'completed', true, false) . '>'
                                        . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                            <p class="description"><?php _e('Hold Ctrl/Cmd to select multiple statuses', 'woo-gst-invoice'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="wgig_tax_export_submit" class="button-primary" value="<?php _e('Export to Excel', 'woo-gst-invoice'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Process export request
     */
    public function process_export() {
        if (isset($_POST['wgig_tax_export_submit']) && isset($_POST['wgig_tax_export_nonce']) && wp_verify_nonce($_POST['wgig_tax_export_nonce'], 'wgig_tax_export_nonce')) {
            
            // Get form data
            $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01');
            $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : date('Y-m-d');
            $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : 'summary';
            $order_statuses = isset($_POST['order_status']) ? array_map('sanitize_text_field', $_POST['order_status']) : array('completed');
            
            // Call the appropriate export function based on export type
            switch ($export_type) {
                case 'detailed':
                    $this->export_detailed_tax_report($start_date, $end_date, $order_statuses);
                    break;
                case 'by_tax_rate':
                    $this->export_tax_by_rate($start_date, $end_date, $order_statuses);
                    break;
                case 'gst_summary':
                    $this->export_gst_summary($start_date, $end_date, $order_statuses);
                    break;
                case 'summary':
                default:
                    $this->export_tax_summary($start_date, $end_date, $order_statuses);
                    break;
            }
        }
    }

    /**
     * Export tax summary
     */
    private function export_tax_summary($start_date, $end_date, $order_statuses) {
        // Prepare query arguments
        $args = array(
            'limit' => -1,
            'status' => $order_statuses,
            'date_created' => $start_date . '...' . $end_date,
        );
        
        // Get orders
        $orders = wc_get_orders($args);
        
        // Initialize tax data
        $tax_data = array();
        $total_tax = 0;
        
        // Loop through orders
        foreach ($orders as $order) {
            $taxes = $order->get_tax_totals();
            
            foreach ($taxes as $tax_code => $tax) {
                $tax_rate_id = $tax->rate_id;
                
                if (!isset($tax_data[$tax_rate_id])) {
                    $tax_data[$tax_rate_id] = array(
                        'name' => $tax->label,
                        'amount' => 0,
                    );
                }
                
                $tax_data[$tax_rate_id]['amount'] += $tax->amount;
                $total_tax += $tax->amount;
            }
        }
        
        // Prepare data for Excel
        $excel_data = array();
        $excel_data[] = array('Tax Name', 'Amount', 'Percentage of Total');
        
        foreach ($tax_data as $rate_id => $tax) {
            $percentage = $total_tax > 0 ? round(($tax['amount'] / $total_tax) * 100, 2) : 0;
            $excel_data[] = array(
                $tax['name'],
                wc_format_decimal($tax['amount'], 2),
                $percentage . '%',
            );
        }
        
        $excel_data[] = array('', '', '');
        $excel_data[] = array('Total Tax', wc_format_decimal($total_tax, 2), '100%');
        
        // Generate Excel file
        $this->generate_excel($excel_data, 'tax_summary');
    }

    /**
     * Export detailed tax report
     */
    private function export_detailed_tax_report($start_date, $end_date, $order_statuses) {
        // Prepare query arguments
        $args = array(
            'limit' => -1,
            'status' => $order_statuses,
            'date_created' => $start_date . '...' . $end_date,
        );
        
        // Get orders
        $orders = wc_get_orders($args);
        
        // Initialize data array
        $excel_data = array();
        $excel_data[] = array(
            'Order ID',
            'Order Date',
            'Order Status',
            'Customer',
            'Tax Name',
            'Tax Rate',
            'Tax Amount',
            'Order Total',
        );
        
        // Loop through orders
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $order_date = $order->get_date_created()->date('Y-m-d H:i:s');
            $order_status = wc_get_order_status_name($order->get_status());
            $customer = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $order_total = $order->get_total();
            
            $taxes = $order->get_tax_totals();
            
            if (empty($taxes)) {
                // If no taxes, add a row with 0 tax
                $excel_data[] = array(
                    $order_id,
                    $order_date,
                    $order_status,
                    $customer,
                    'No Tax',
                    '0%',
                    '0.00',
                    wc_format_decimal($order_total, 2),
                );
            } else {
                foreach ($taxes as $tax_code => $tax) {
                    // Get tax rate
                    $rate_id = $tax->rate_id;
                    global $wpdb;
                    $tax_rate = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_id = %d",
                        $rate_id
                    ));
                    $rate_percent = isset($tax_rate->tax_rate) ? $tax_rate->tax_rate . '%' : 'N/A';
                    
                    $excel_data[] = array(
                        $order_id,
                        $order_date,
                        $order_status,
                        $customer,
                        $tax->label,
                        $rate_percent,
                        wc_format_decimal($tax->amount, 2),
                        wc_format_decimal($order_total, 2),
                    );
                }
            }
        }
        
        // Generate Excel file
        $this->generate_excel($excel_data, 'detailed_tax_report');
    }

    /**
     * Export tax by rate
     */
    private function export_tax_by_rate($start_date, $end_date, $order_statuses) {
        // Get all tax rates
        $tax_rates = $this->get_all_tax_rates();
        
        // Prepare query arguments
        $args = array(
            'limit' => -1,
            'status' => $order_statuses,
            'date_created' => $start_date . '...' . $end_date,
        );
        
        // Get orders
        $orders = wc_get_orders($args);
        
        // Initialize data array
        $tax_data = array();
        foreach ($tax_rates as $rate_id => $rate) {
            $tax_data[$rate_id] = array(
                'name' => $rate['name'],
                'rate' => $rate['rate'],
                'amount' => 0,
                'order_count' => 0,
            );
        }
        
        // Loop through orders
        foreach ($orders as $order) {
            $taxes = $order->get_tax_totals();
            
            foreach ($taxes as $tax_code => $tax) {
                $tax_rate_id = $tax->rate_id;
                
                if (isset($tax_data[$tax_rate_id])) {
                    $tax_data[$tax_rate_id]['amount'] += $tax->amount;
                    $tax_data[$tax_rate_id]['order_count']++;
                }
            }
        }
        
        // Prepare data for Excel
        $excel_data = array();
        $excel_data[] = array('Tax Name', 'Tax Rate', 'Total Amount', 'Order Count', 'Average per Order');
        
        foreach ($tax_data as $rate_id => $tax) {
            $avg_per_order = $tax['order_count'] > 0 ? $tax['amount'] / $tax['order_count'] : 0;
            
            $excel_data[] = array(
                $tax['name'],
                $tax['rate'] . '%',
                wc_format_decimal($tax['amount'], 2),
                $tax['order_count'],
                wc_format_decimal($avg_per_order, 2),
            );
        }
        
        // Generate Excel file
        $this->generate_excel($excel_data, 'tax_by_rate');
    }

    /**
     * Export GST summary (specific for Indian GST)
     */
    private function export_gst_summary($start_date, $end_date, $order_statuses) {
        // Prepare query arguments
        $args = array(
            'limit' => -1,
            'status' => $order_statuses,
            'date_created' => $start_date . '...' . $end_date,
        );
        
        // Get orders
        $orders = wc_get_orders($args);
        
        // Initialize data arrays
        $cgst_data = array();
        $sgst_data = array();
        $igst_data = array();
        $total_taxable = 0;
        $total_cgst = 0;
        $total_sgst = 0;
        $total_igst = 0;
        
        // Loop through orders
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $is_igst = get_post_meta($order_id, '_wgig_is_igst', true);
            
            // If IGST status not stored, determine it
            if ($is_igst === '') {
                // Default to IGST if company and billing states don't match
                $company_state_code = get_option('wgig_state_code', '');
                $billing_state_code = get_post_meta($order_id, '_billing_state_code', true);
                $is_igst = ($billing_state_code !== $company_state_code);
            }
            
            // Get order items
            $items = $order->get_items();
            
            // Process each item
            foreach ($items as $item) {
                $product = $item->get_product();
                $line_total = $item->get_total(); // Total without tax
                
                // Get or set default GST rate
                $gst_rate = '5'; // Default GST rate
                if ($product) {
                    $product_gst_rate = get_post_meta($product->get_id(), '_gst_rate', true);
                    if (!empty($product_gst_rate)) {
                        $gst_rate = $product_gst_rate;
                    }
                }
                
                // Add to taxable amount
                $total_taxable += $line_total;
                
                // Calculate GST
                $tax_amount = $line_total * ($gst_rate / 100);
                
                // Add to appropriate GST category
                if ($is_igst) {
                    // IGST
                    $total_igst += $tax_amount;
                    
                    if (!isset($igst_data[$gst_rate])) {
                        $igst_data[$gst_rate] = array(
                            'taxable' => 0,
                            'tax' => 0
                        );
                    }
                    
                    $igst_data[$gst_rate]['taxable'] += $line_total;
                    $igst_data[$gst_rate]['tax'] += $tax_amount;
                } else {
                    // CGST & SGST (half each)
                    $cgst_amount = $tax_amount / 2;
                    $sgst_amount = $tax_amount / 2;
                    
                    $total_cgst += $cgst_amount;
                    $total_sgst += $sgst_amount;
                    
                    if (!isset($cgst_data[$gst_rate])) {
                        $cgst_data[$gst_rate] = array(
                            'taxable' => 0,
                            'tax' => 0
                        );
                    }
                    
                    if (!isset($sgst_data[$gst_rate])) {
                        $sgst_data[$gst_rate] = array(
                            'taxable' => 0,
                            'tax' => 0
                        );
                    }
                    
                    $cgst_data[$gst_rate]['taxable'] += $line_total;
                    $cgst_data[$gst_rate]['tax'] += $cgst_amount;
                    
                    $sgst_data[$gst_rate]['taxable'] += $line_total;
                    $sgst_data[$gst_rate]['tax'] += $sgst_amount;
                }
            }
        }
        
        // Prepare data for Excel
        $excel_data = array();
        
        // Add header
        $excel_data[] = array('GST Summary Report', '', '', '');
        $excel_data[] = array('From: ' . date('d-m-Y', strtotime($start_date)) . ' To: ' . date('d-m-Y', strtotime($end_date)), '', '', '');
        $excel_data[] = array('', '', '', '');
        
        // CGST Summary
        if (!empty($cgst_data)) {
            $excel_data[] = array('CGST Summary', '', '', '');
            $excel_data[] = array('Rate', 'Taxable Amount', 'CGST Amount', '');
            
            foreach ($cgst_data as $rate => $data) {
                $excel_data[] = array(
                    $rate . '%',
                    wc_format_decimal($data['taxable'], 2),
                    wc_format_decimal($data['tax'], 2),
                    ''
                );
            }
            
            $excel_data[] = array('Total', wc_format_decimal($total_taxable, 2), wc_format_decimal($total_cgst, 2), '');
            $excel_data[] = array('', '', '', '');
        }
        
        // SGST Summary
        if (!empty($sgst_data)) {
            $excel_data[] = array('SGST Summary', '', '', '');
            $excel_data[] = array('Rate', 'Taxable Amount', 'SGST Amount', '');
            
            foreach ($sgst_data as $rate => $data) {
                $excel_data[] = array(
                    $rate . '%',
                    wc_format_decimal($data['taxable'], 2),
                    wc_format_decimal($data['tax'], 2),
                    ''
                );
            }
            
            $excel_data[] = array('Total', wc_format_decimal($total_taxable, 2), wc_format_decimal($total_sgst, 2), '');
            $excel_data[] = array('', '', '', '');
        }
        
        // IGST Summary
        if (!empty($igst_data)) {
            $excel_data[] = array('IGST Summary', '', '', '');
            $excel_data[] = array('Rate', 'Taxable Amount', 'IGST Amount', '');
            
            foreach ($igst_data as $rate => $data) {
                $excel_data[] = array(
                    $rate . '%',
                    wc_format_decimal($data['taxable'], 2),
                    wc_format_decimal($data['tax'], 2),
                    ''
                );
            }
            
            $excel_data[] = array('Total', wc_format_decimal($total_taxable, 2), wc_format_decimal($total_igst, 2), '');
            $excel_data[] = array('', '', '', '');
        }
        
        // Grand Total
        $excel_data[] = array('Grand Total', '', '', '');
        $excel_data[] = array('Total Taxable Amount', wc_format_decimal($total_taxable, 2), '', '');
        $excel_data[] = array('Total Tax Amount', wc_format_decimal($total_cgst + $total_sgst + $total_igst, 2), '', '');
        
        // Generate Excel file
        $this->generate_excel($excel_data, 'gst_summary');
    }

    /**
     * Get all tax rates
     */
    private function get_all_tax_rates() {
        global $wpdb;
        
        $tax_rates = array();
        
        $results = $wpdb->get_results("
            SELECT tax_rate_id, tax_rate_name, tax_rate
            FROM {$wpdb->prefix}woocommerce_tax_rates
        ");
        
        foreach ($results as $rate) {
            $tax_rates[$rate->tax_rate_id] = array(
                'name' => $rate->tax_rate_name,
                'rate' => $rate->tax_rate,
            );
        }
        
        return $tax_rates;
    }

    /**
     * Generate Excel file
     */
    private function generate_excel($data, $filename) {
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xls"');
        header('Cache-Control: max-age=0');
        
        // Create a file pointer
        $output = fopen('php://output', 'w');
        
        // BOM (Byte Order Mark)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Output each row of the data
        foreach ($data as $row) {
            fputcsv($output, $row, "\t");
        }
        
        fclose($output);
        exit;
    }
}

// Initialize the class
new WC_GST_Invoice_Tax_Export();