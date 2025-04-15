<?php
/**
 * WC_GST_Invoice_Generator Class
 *
 * Handles the invoice generation functionality
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_Generator {

    /**
     * Constructor
     */
    public function __construct() {
        // Add invoice number on order creation
        add_action('woocommerce_checkout_order_processed', array($this, 'add_invoice_number_on_order_creation'), 10, 3);
        
        // Add invoice number when order status changes
        add_action('woocommerce_order_status_changed', array($this, 'add_invoice_number_on_status_change'), 10, 4);
    }

    /**
     * Add invoice number on order creation
     *
     * @param int $order_id
     * @param array $posted_data
     * @param WC_Order $order
     */
    public function add_invoice_number_on_order_creation($order_id, $posted_data, $order) {
        // Only generate invoice for processing or completed orders
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            // Check if invoice number already exists
            $existing_invoice = get_post_meta($order_id, '_wgig_invoice_number', true);
            if (!$existing_invoice) {
                $this->generate_invoice($order_id);
            }
        }
    }

    /**
     * Add invoice number when order status changes
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order $order
     */
    public function add_invoice_number_on_status_change($order_id, $old_status, $new_status, $order) {
        // Only generate invoice for processing or completed orders
        if ($new_status === 'processing' || $new_status === 'completed') {
            // Check if invoice number already exists
            $existing_invoice = get_post_meta($order_id, '_wgig_invoice_number', true);
            if (!$existing_invoice) {
                $this->generate_invoice($order_id);
            }
        }
    }

    /**
     * Generate invoice for an order
     *
     * @param int $order_id
     * @return string|bool Invoice number or false on failure
     */
    public function generate_invoice($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Check if invoice number already exists
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        if (!$invoice_number) {
            $invoice_number = $this->generate_invoice_number($order_id);
        }
        
        return $invoice_number;
    }

    /**
     * Generate invoice number
     *
     * @param int $order_id
     * @return string Invoice number
     */
    public function generate_invoice_number($order_id) {
        $prefix = get_option('wgig_invoice_prefix', '');
        $next_number = get_option('wgig_next_invoice_number', 1);
        
        $invoice_number = $prefix . sprintf('%06d', $next_number);
        $invoice_date = date('Y-m-d');
        
        update_post_meta($order_id, '_wgig_invoice_number', $invoice_number);
        update_post_meta($order_id, '_wgig_invoice_date', $invoice_date);
        
        // Increment and save next invoice number
        update_option('wgig_next_invoice_number', $next_number + 1);
        
        return $invoice_number;
    }

    /**
     * Get tax rates from order
     *
     * @param WC_Order $order
     * @return array Tax rates
     */
    public function get_tax_rates($order) {
        $tax_rates = array();
        $tax_items = $order->get_items('tax');
        
        foreach ($tax_items as $tax_item) {
            $rate_id = $tax_item->get_rate_id();
            $rate_percent = WC_Tax::get_rate_percent($rate_id);
            $rate_value = str_replace('%', '', $rate_percent);
            
            if (!empty($rate_value)) {
                $tax_rates[$rate_id] = $rate_value;
            }
        }
        
        return $tax_rates;
    }

    /**
     * Check if tax items include CGST and SGST
     *
     * @param WC_Order $order
     * @return bool True if order has both CGST and SGST taxes
     */
    public function has_cgst_sgst($order) {
        $has_cgst = false;
        $has_sgst = false;
        
        foreach ($order->get_items('tax') as $tax_item) {
            $rate_id = $tax_item->get_rate_id();
            $tax_rate = WC_Tax::_get_tax_rate($rate_id);
            
            if ($tax_rate) {
                $tax_rate_name = strtolower($tax_rate['tax_rate_name']);
                
                if (strpos($tax_rate_name, 'cgst') !== false) {
                    $has_cgst = true;
                }
                
                if (strpos($tax_rate_name, 'sgst') !== false) {
                    $has_sgst = true;
                }
            }
        }
        
        return ($has_cgst && $has_sgst);
    }

    /**
     * Get GST rate for a product
     *
     * @param WC_Product $product
     * @return string GST rate
     */
    public function get_product_gst_rate($product) {
        $gst_rate = '';
        
        if ($product) {
            $gst_rate = get_post_meta($product->get_id(), '_gst_rate', true);
            
            // If no custom GST rate, try to get from tax class
            if (!$gst_rate) {
                $tax_class = $product->get_tax_class();
                
                if ($tax_class) {
                    // Try to extract rate from tax class name
                    if (preg_match('/(\d+)%/', $tax_class, $matches)) {
                        $gst_rate = $matches[1];
                    }
                }
            }
        }
        
        // Default to 5% if no rate found
        if (!$gst_rate) {
            $gst_rate = '5';
        }
        
        return $gst_rate;
    }

    /**
     * Get HSN code for a product
     *
     * @param WC_Product $product
     * @return string HSN code
     */
    public function get_product_hsn_code($product) {
        $hsn_code = '';
        
        if ($product) {
            $hsn_code = get_post_meta($product->get_id(), '_hsn_code', true);
        }
        
        return $hsn_code;
    }

    /**
     * Get tax details for an order
     *
     * @param WC_Order $order
     * @return array Tax details
     */
    public function get_tax_details($order) {
        $tax_details = array(
            'rates' => array(),
            'totals' => array(
                'taxable' => 0,
                'igst' => 0,
                'cgst' => 0,
                'sgst' => 0
            )
        );
        
        // First try to determine GST type from the tax items directly
        $is_igst = !$this->has_cgst_sgst($order);
        
        // If no specific tax items found, use state codes
        if (!$is_igst) {
            // If we have CGST and SGST tax items, it's definitely not IGST
            $is_igst = false;
        } else {
            // Get company and billing state codes
            $company_state_code = get_option('wgig_state_code', '');
            $billing_state_code = get_post_meta($order->get_id(), '_billing_state_code', true);
            
            // Only use state code comparison if we have both codes
            if (!empty($company_state_code) && !empty($billing_state_code)) {
                $is_igst = ($billing_state_code !== $company_state_code);
            }
        }
        
        // Save the IGST/CGST determination for use by the PDF generator
        update_post_meta($order->get_id(), '_wgig_is_igst', $is_igst);
        
        // Get order items
        $items = $order->get_items();
        
        foreach ($items as $item) {
            $product = $item->get_product();
            $gst_rate = $this->get_product_gst_rate($product);
            
            $line_total = $item->get_total();
            
            // Initialize rate if not exists
            if (!isset($tax_details['rates'][$gst_rate])) {
                $tax_details['rates'][$gst_rate] = array(
                    'taxable' => 0,
                    'igst' => 0,
                    'cgst' => 0,
                    'sgst' => 0
                );
            }
            
            // Add to taxable amount
            $tax_details['rates'][$gst_rate]['taxable'] += $line_total;
            $tax_details['totals']['taxable'] += $line_total;
            
            // Calculate tax
            $tax_amount = $line_total * ($gst_rate / 100);
            
            if ($is_igst) {
                $tax_details['rates'][$gst_rate]['igst'] += $tax_amount;
                $tax_details['totals']['igst'] += $tax_amount;
            } else {
                $tax_details['rates'][$gst_rate]['cgst'] += $tax_amount / 2;
                $tax_details['rates'][$gst_rate]['sgst'] += $tax_amount / 2;
                $tax_details['totals']['cgst'] += $tax_amount / 2;
                $tax_details['totals']['sgst'] += $tax_amount / 2;
            }
        }
        
        return $tax_details;
    }

    /**
     * Convert number to words
     *
     * @param float $number
     * @return string Number in words
     */
    public function number_to_words($number) {
        $decimal = round($number - ($no = floor($number)), 2) * 100;
        $hundred = null;
        $digits_length = strlen($no);
        $i = 0;
        $str = array();
        $words = array(
            0 => '', 1 => 'one', 2 => 'two',
            3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            7 => 'seven', 8 => 'eight', 9 => 'nine',
            10 => 'ten', 11 => 'eleven', 12 => 'twelve',
            13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
            16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
            19 => 'nineteen', 20 => 'twenty', 30 => 'thirty',
            40 => 'forty', 50 => 'fifty', 60 => 'sixty',
            70 => 'seventy', 80 => 'eighty', 90 => 'ninety'
        );
        $digits = array('', 'hundred', 'thousand', 'lakh', 'crore');
        
        while ($i < $digits_length) {
            $divider = ($i == 2) ? 10 : 100;
            $number = floor($no % $divider);
            $no = floor($no / $divider);
            $i += $divider == 10 ? 1 : 2;
            
            if ($number) {
                $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                $str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
            } else {
                $str[] = null;
            }
        }
        
        $rupees = implode('', array_reverse($str));
        $paise = ($decimal > 0) ? " and " . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . " paise" : '';
        
        return ($rupees ? $rupees . 'rupees' : '') . $paise;
    }
}