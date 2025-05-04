<?php
/**
 * Invoice template for GST Invoice Generator
 * 
 * This file serves as a reference for the PDF generation process.
 * The actual PDF is generated in the class-wc-gst-invoice-pdf.php file.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the template data
 *
 * @param WC_Order $order Order object
 * @param array $invoice_data Invoice data
 * @return array Template data
 */
function wgig_get_invoice_template_data($order, $invoice_data) {
    // Company details
    $company_name = get_option('wgig_company_name', '');
    $company_address = get_option('wgig_company_address', '');
    $company_phone = get_option('wgig_company_phone', '');
    $company_email = get_option('wgig_company_email', '');
    $company_gstin = get_option('wgig_gstin', '');
    $company_state_code = get_option('wgig_state_code', '');
    $declaration_text = get_option('wgig_declaration_text', '');
    
    // Invoice details
    $invoice_number = $invoice_data['invoice_number'];
    $invoice_date = $invoice_data['invoice_date'];
    
    // Customer details
    $billing_first_name = $order->get_billing_first_name();
    $billing_last_name = $order->get_billing_last_name();
    $billing_company = $order->get_billing_company();
    $billing_address_1 = $order->get_billing_address_1();
    $billing_address_2 = $order->get_billing_address_2();
    $billing_city = $order->get_billing_city();
    $billing_state = $order->get_billing_state();
    $billing_postcode = $order->get_billing_postcode();
    $billing_country = $order->get_billing_country();
    $billing_phone = $order->get_billing_phone();
    $billing_email = $order->get_billing_email();
    
    // Shipping details
    $shipping_first_name = $order->get_shipping_first_name();
    $shipping_last_name = $order->get_shipping_last_name();
    $shipping_company = $order->get_shipping_company();
    $shipping_address_1 = $order->get_shipping_address_1();
    $shipping_address_2 = $order->get_shipping_address_2();
    $shipping_city = $order->get_shipping_city();
    $shipping_state = $order->get_shipping_state();
    $shipping_postcode = $order->get_shipping_postcode();
    $shipping_country = $order->get_shipping_country();
    
    // Order details
    $order_id = $order->get_id();
    $order_number = $order->get_order_number();
    $order_date = $order->get_date_created()->date_i18n(get_option('date_format'));
    $payment_method = $order->get_payment_method_title();
    
    // Get customer GSTIN and state code from meta
    $customer_gstin = get_post_meta($order_id, '_billing_gstin', true);
    $customer_state_code = get_post_meta($order_id, '_billing_state_code', true);
    
    // Get order items
    $items = $order->get_items();
    $order_items = array();
    
    foreach ($items as $item) {
        $product = $item->get_product();
        
        $order_items[] = array(
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'price' => $order->get_item_total($item, false, false),
            'discount' => $item->get_subtotal() - $item->get_total(),
            'total' => $item->get_total(),
            'hsn_code' => $product ? get_post_meta($product->get_id(), '_hsn_code', true) : '',
            'gst_rate' => $product ? get_post_meta($product->get_id(), '_gst_rate', true) : '5',
        );
    }
    
    // Check if this is an IGST or CGST/SGST transaction
    $is_igst = get_post_meta($order_id, '_wgig_is_igst', true);
    
    // If not explicitly set, determine based on tax items
    if ($is_igst === '') {
        // Check for CGST/SGST tax items
        $has_cgst = false;
        $has_sgst = false;
        
        foreach ($order->get_items('tax') as $tax_item) {
            $tax_rate_id = $tax_item->get_rate_id();
            $tax_rate = WC_Tax::_get_tax_rate($tax_rate_id);
            
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
        
        // If both CGST and SGST are present, it's not IGST
        $is_igst = !($has_cgst && $has_sgst);
    }
    
    // Calculate tax details
    $tax_details = array(
        'rates' => array(),
        'totals' => array(
            'taxable' => 0,
            'igst' => 0,
            'cgst' => 0,
            'sgst' => 0
        )
    );
    
    foreach ($order_items as $item) {
        $line_total = $item['total'];
        $gst_rate = $item['gst_rate'] ? $item['gst_rate'] : '5';
        
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
    
    // Calculate final amounts
    $total_tax = $tax_details['totals']['igst'] + $tax_details['totals']['cgst'] + $tax_details['totals']['sgst'];
    $total_amount = $tax_details['totals']['taxable'] + $total_tax;
    $round_off = round($total_amount) - $total_amount;
    $final_amount = round($total_amount);
    
    // Return template data
    return array(
        'company' => array(
            'name' => $company_name,
            'address' => $company_address,
            'phone' => $company_phone,
            'email' => $company_email,
            'gstin' => $company_gstin,
            'state_code' => $company_state_code
        ),
        'invoice' => array(
            'number' => $invoice_number,
            'date' => $invoice_date,
            'declaration' => $declaration_text
        ),
        'customer' => array(
            'name' => $billing_first_name . ' ' . $billing_last_name,
            'company' => $billing_company,
            'billing_address' => array(
                'address_1' => $billing_address_1,
                'address_2' => $billing_address_2,
                'city' => $billing_city,
                'state' => $billing_state,
                'postcode' => $billing_postcode,
                'country' => $billing_country
            ),
            'shipping_address' => array(
                'address_1' => $shipping_address_1 ? $shipping_address_1 : $billing_address_1,
                'address_2' => $shipping_address_2 ? $shipping_address_2 : $billing_address_2,
                'city' => $shipping_city ? $shipping_city : $billing_city,
                'state' => $shipping_state ? $shipping_state : $billing_state,
                'postcode' => $shipping_postcode ? $shipping_postcode : $billing_postcode,
                'country' => $shipping_country ? $shipping_country : $billing_country
            ),
            'phone' => $billing_phone,
            'email' => $billing_email,
            'gstin' => $customer_gstin,
            'state_code' => $customer_state_code
        ),
        'order' => array(
            'id' => $order_id,
            'number' => $order_number,
            'date' => $order_date,
            'payment_method' => $payment_method,
            'items' => $order_items
        ),
        'tax_details' => $tax_details,
        'totals' => array(
            'taxable' => $tax_details['totals']['taxable'],
            'igst' => $tax_details['totals']['igst'],
            'cgst' => $tax_details['totals']['cgst'],
            'sgst' => $tax_details['totals']['sgst'],
            'tax' => $total_tax,
            'round_off' => $round_off,
            'final' => $final_amount
        ),
        'is_igst' => $is_igst
    );
}

<div class="signature-section" style="margin-top: 20px; page-break-inside: avoid;">
    <div class="signature-container" style="display: flex; flex-direction: column; align-items: center; margin-bottom: 20px;">
        <?php if (!empty($signature_image)): ?>
            <div class="signature-image-container" style="margin-bottom: 10px; max-width: 200px;">
                <img src="<?php echo esc_url($signature_image); ?>" alt="Signature" style="max-width: 100%; height: auto;">
            </div>
        <?php endif; ?>
        <div class="signature-title" style="font-weight: bold; margin-top: 10px;">
            <?php _e('Authorized Signatory', 'woo-gst-invoice'); ?>
        </div>
    </div>
</div>