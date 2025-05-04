<?php
/**
 * WC_GST_Invoice_PDF Class
 *
 * Handles the PDF generation functionality with perfect alignment and proper spacing
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_PDF {

    /**
     * Constructor
     */
    public function __construct() {
        // Check if TCPDF library is available
        if (!class_exists('TCPDF')) {
            // Try to include TCPDF library
            if (file_exists(WGIG_PLUGIN_DIR . 'includes/tcpdf/tcpdf.php')) {
                try {
                    require_once WGIG_PLUGIN_DIR . 'includes/tcpdf/tcpdf.php';
                } catch (Exception $e) {
                    throw new Exception(
                        sprintf(
                            __('Failed to load TCPDF library: %s. Please check the installation and file permissions.', 'woo-gst-invoice'),
                            $e->getMessage()
                        )
                    );
                }
            } else {
                throw new Exception(
                    sprintf(
                        __('TCPDF library is not available at %s. Please ensure the TCPDF library is properly installed in the includes/tcpdf directory.', 'woo-gst-invoice'),
                        WGIG_PLUGIN_DIR . 'includes/tcpdf/tcpdf.php'
                    )
                );
            }
        }

        // Verify TCPDF is properly loaded and has required methods
        if (!class_exists('TCPDF')) {
            throw new Exception(__('Failed to load TCPDF library. Please check the installation.', 'woo-gst-invoice'));
        }

        // Verify TCPDF has required methods
        $required_methods = array('SetCreator', 'SetAuthor', 'SetTitle', 'SetSubject', 'SetKeywords', 'SetMargins', 'AddPage');
        foreach ($required_methods as $method) {
            if (!method_exists('TCPDF', $method)) {
                throw new Exception(
                    sprintf(
                        __('TCPDF library is missing required method: %s. Please check the installation.', 'woo-gst-invoice'),
                        $method
                    )
                );
            }
        }
    }

    /**
     * Generate and download PDF for an order
     *
     * @param int $order_id
     */
    public function generate_and_download_pdf($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found.', 'woo-gst-invoice'));
        }
        
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        if (!$invoice_number) {
            $invoice_generator = new WC_GST_Invoice_Generator();
            $invoice_number = $invoice_generator->generate_invoice($order_id);
            
            if (!$invoice_number) {
                wp_die(__('Failed to generate invoice.', 'woo-gst-invoice'));
            }
        }
        
        $pdf = $this->create_pdf($order_id);
        
        // Make sure we clear any previous output before sending the PDF
        if (ob_get_contents()) ob_end_clean();
        
        // Set appropriate headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Invoice_' . $invoice_number . '.pdf"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // Output PDF
        $pdf->Output('Invoice_' . $invoice_number . '.pdf', 'D');
        exit;
    }

    /**
     * Generate and download multiple PDFs
     */
    public function generate_and_download_multiple_pdfs($order_ids) {
        // Create a new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document properties
        $pdf->SetCreator('WooCommerce GST Invoice Generator');
        $pdf->SetAuthor(get_option('wgig_company_name', ''));
        $pdf->SetTitle('GST Invoices');
        $pdf->SetSubject('GST Invoices');
        
        // Remove header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        
        // Process each order
        foreach ($order_ids as $order_id) {
            // Add a new page for each order
            $pdf->AddPage();
            
            // Add the order to the PDF
            $this->add_order_to_pdf($pdf, $order_id);
        }
        
        // Make sure we clear any previous output before sending the PDF
        if (ob_get_contents()) ob_end_clean();
        
        // Set appropriate headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="GST_Invoices.pdf"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // Output PDF
        $pdf->Output('GST_Invoices.pdf', 'D');
        exit;
    }

    /**
     * Clean up temporary files
     *
     * @param string $dir
     */
    private function clean_up_temp_files($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->clean_up_temp_files($file);
                rmdir($file);
            }
        }
        
        rmdir($dir);
    }

    /**
     * Creates a formatted table header with proper alignment and styling
     * 
     * @param TCPDF $pdf The PDF object
     * @param array $widths Array of column widths
     * @param array $headers Array of header texts
     * @param array $aligns Array of alignments (L, C, R)
     */
    private function create_table_header($pdf, $widths, $headers, $aligns = []) {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetFont('helvetica', 'B', 8);
        
        foreach ($widths as $index => $width) {
            $header = isset($headers[$index]) ? $headers[$index] : '';
            $align = isset($aligns[$index]) ? $aligns[$index] : 'C';
            $pdf->Cell($width, 7, $header, 1, ($index == count($widths) - 1 ? 1 : 0), $align, true);
        }
        
        $pdf->SetFont('helvetica', '', 8);
    }

    /**
     * Create PDF for an order
     * @param int $order_id
     * @return FPDF
     */
    public function create_pdf($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-gst-invoice'));
        }

        $invoice_generator = new WC_GST_Invoice_Generator();
        
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        $invoice_date = get_post_meta($order_id, '_wgig_invoice_date', true);
        
        // Get IGST/CGST determination - use the stored value or calculate it if not available
        $is_igst = get_post_meta($order_id, '_wgig_is_igst', true);
        if ($is_igst === '') {
            // If not stored, determine based on tax items
            $is_igst = !$invoice_generator->has_cgst_sgst($order);
        }
        
        // Company details
        $company_name = get_option('wgig_company_name', '');
        $company_address = get_option('wgig_company_address', '');
        $company_phone = get_option('wgig_company_phone', '');
        $company_email = get_option('wgig_company_email', '');
        $company_gstin = get_option('wgig_gstin', '');
        $company_state_code = get_option('wgig_state_code', '');
        $declaration_text = get_option('wgig_declaration_text', '');
        $signature_image_id = get_option('wgig_signature_image', '');
        
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
        
        // Get tax details
        $tax_details = $invoice_generator->get_tax_details($order);
        
        // Create PDF with appropriate margins
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('WooCommerce GST Invoice Generator');
        $pdf->SetAuthor($company_name);
        $pdf->SetTitle('Invoice #' . $invoice_number);
        $pdf->SetSubject('Invoice #' . $invoice_number);
        $pdf->SetKeywords('Invoice, GST, WooCommerce');
        
        // Remove header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        
        // Add a page
        $pdf->AddPage();
        
        // ========== COMPANY HEADER SECTION ==========
        // Company name centered and bold
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, $company_name, 0, 1, 'C');
        
        // Company address centered
        $pdf->SetFont('helvetica', '', 9);
        if (!empty($company_address)) {
            // Split address by line breaks if any
            $address_lines = explode("\n", $company_address);
            foreach ($address_lines as $line) {
                $pdf->Cell(0, 5, trim($line), 0, 1, 'C');
            }
        }
        
        // Contact info (phone and email) centered
        $contact_line = '';
        if (!empty($company_phone)) {
            $contact_line .= 'Ph: ' . $company_phone;
        }
        if (!empty($company_email)) {
            if (!empty($contact_line)) {
                $contact_line .= ' | ';
            }
            $contact_line .= 'Email: ' . $company_email;
        }
        if (!empty($contact_line)) {
            $pdf->Cell(0, 5, $contact_line, 0, 1, 'C');
        }
        
        // GSTIN and State Code centered
        $gstin_line = '';
        if (!empty($company_gstin)) {
            $gstin_line .= 'GSTIN: ' . $company_gstin;
        }
        if (!empty($company_state_code)) {
            if (!empty($gstin_line)) {
                $gstin_line .= ' | ';
            }
            $gstin_line .= 'State Code: ' . $company_state_code;
        }
        if (!empty($gstin_line)) {
            $pdf->Cell(0, 5, $gstin_line, 0, 1, 'C');
        }
        
        // Space before invoice title
        $pdf->Ln(3);
        
        // TAX INVOICE title with border
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'TAX INVOICE', 1, 1, 'C');
        
        // Space after invoice title
        $pdf->Ln(4);
        
        // ========== CUSTOMER DETAILS SECTION ==========
        // Create a proper table for recipient and invoice details
        $pdf->SetFont('helvetica', 'B', 9);
        
        // Define the table structure - two equal columns
        $page_width = $pdf->getPageWidth() - 20; // Account for 10mm margins on each side
        $col_width = $page_width / 2;
        
        // Table headers
        $pdf->Cell($col_width, 6, 'Recipient Details:', 'TB', 0, 'L');
        $pdf->Cell($col_width, 6, 'Invoice Details:', 'TB', 1, 'L');
        
        // Switch to normal font for content
        $pdf->SetFont('helvetica', '', 9);
        
        // Name and Invoice Number row
        $pdf->Cell(25, 6, 'Name:', 0, 0, 'L');
        $recipient_name = $billing_first_name . ' ' . $billing_last_name;
        if (!empty($billing_company)) {
            $recipient_name .= ' (' . $billing_company . ')';
        }
        $pdf->Cell($col_width - 25, 6, $recipient_name, 0, 0, 'L');
        
        $pdf->Cell(25, 6, 'Invoice No.:', 0, 0, 'L');
        $pdf->Cell($col_width - 25, 6, $invoice_number, 0, 1, 'L');
        
        // Billing Address row - save starting Y position
        $start_y = $pdf->GetY();
        $pdf->Cell(25, 6, 'Billing Address:', 0, 0, 'L');
        
        // Format billing address properly
        $billing_address = $billing_address_1;
        if (!empty($billing_address_2)) $billing_address .= ', ' . $billing_address_2;
        if (!empty($billing_city)) $billing_address .= ', ' . $billing_city;
        if (!empty($billing_state)) $billing_address .= ', ' . $billing_state;
        if (!empty($billing_postcode)) $billing_address .= ' - ' . $billing_postcode;
        
        // MultiCell for potentially multi-line address
        $pdf->MultiCell($col_width - 25, 6, $billing_address, 0, 'L');
        $end_y = $pdf->GetY();
        
        // Invoice date on right side aligned with billing address
        $pdf->SetXY($col_width + 10, $start_y);
        $pdf->Cell(25, 6, 'Invoice Date:', 0, 0, 'L');
        $pdf->Cell($col_width - 25, 6, date('d/m/Y', strtotime($invoice_date)), 0, 1, 'L');
        
        // Return to position after billing address and add more space
        $pdf->SetY($end_y + 6); // Increased space after billing address
        
        // Shipping Address with increased spacing between title and content
        $pdf->Cell(25, 8, 'Shipping Address:', 0, 0, 'L');  // Increased height from 6 to 8
        
        // Add extra vertical space before shipping address content
        $current_y = $pdf->GetY();
        $pdf->SetY($current_y + 2);  // Add 2mm extra space
        $pdf->SetX(35);  // Reset X position to align with content
        
        // Format shipping address
        if (!empty($shipping_address_1)) {
            $shipping_address = $shipping_address_1;
            if (!empty($shipping_address_2)) $shipping_address .= ', ' . $shipping_address_2;
            if (!empty($shipping_city)) $shipping_address .= ', ' . $shipping_city;
            if (!empty($shipping_state)) $shipping_address .= ', ' . $shipping_state;
            if (!empty($shipping_postcode)) $shipping_address .= ' - ' . $shipping_postcode;
        } else {
            $shipping_address = 'Same as billing address';
        }
        
        $pdf->MultiCell($col_width - 25, 6, $shipping_address, 0, 'L');
        
        // Add more space after shipping address
        $pdf->Ln(6);
        
        // GSTIN, State Code, and Place of Supply clearly formatted
        $pdf->Cell(25, 6, 'GSTIN:', 0, 0, 'L');
        $billing_gstin = get_post_meta($order_id, '_billing_gstin', true);
        $pdf->Cell($col_width - 25, 6, !empty($billing_gstin) ? $billing_gstin : '', 0, 1, 'L');
        
        $pdf->Cell(25, 6, 'State Code:', 0, 0, 'L');
        $billing_state_code = get_post_meta($order_id, '_billing_state_code', true);
        $pdf->Cell($col_width - 25, 6, !empty($billing_state_code) ? $billing_state_code : '', 0, 1, 'L');
        
        $pdf->Cell(25, 6, 'Place of Supply:', 0, 0, 'L');
        $pdf->Cell($col_width - 25, 6, !empty($billing_state) ? $billing_state : '', 0, 1, 'L');
        
        // Add more space before items table
        $pdf->Ln(6);
        
        // ========== DETAILS OF GOODS SUPPLIED TABLE ==========
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'Details of Goods Supplied:', 0, 1, 'L');
        
        // Calculate optimal column widths based on content
        $items = $order->get_items();
        $max_desc_length = 0;
        
        foreach ($items as $item) {
            $max_desc_length = max($max_desc_length, strlen($item->get_name()));
        }
        
        // Adjust column widths based on content length
        $total_width = 190; // Total available width
        
        // Base widths for fixed columns
        $sno_width = 10;
        $hsn_width = 20;
        $gst_rate_width = 20;
        $qty_width = 15;
        $rate_width = 25;
        $discount_width = 25;
        $taxable_width = 25;
        
        // Calculate remaining width for description column
        $remaining_width = $total_width - ($sno_width + $hsn_width + $gst_rate_width + $qty_width + 
                                          $rate_width + $discount_width + $taxable_width);
        
        // Minimum width for description column
        $desc_width = max(50, $remaining_width);
        
        // Adjust total width if needed
        if ($desc_width < 50) {
            // Reduce other columns proportionally
            $reduction_factor = (50 - $desc_width) / ($total_width - $desc_width);
            $hsn_width = floor($hsn_width * (1 - $reduction_factor));
            $gst_rate_width = floor($gst_rate_width * (1 - $reduction_factor));
            $rate_width = floor($rate_width * (1 - $reduction_factor));
            $discount_width = floor($discount_width * (1 - $reduction_factor));
            $taxable_width = floor($taxable_width * (1 - $reduction_factor));
            $desc_width = 50;
        }
        
        // Combine the column widths
        $widths = array(
            $sno_width,                 // S.No
            $desc_width,                // Description
            $hsn_width,                 // HSN Code
            $gst_rate_width,            // GST Rate
            $qty_width,                 // Quantity
            $rate_width,                // Rate
            $discount_width,            // Discount
            $taxable_width              // Taxable Value
        );
        
        $headers = array(
            'S.No',
            'Description of Goods',
            'HSN Code',
            'GST Rate %',
            'Quantity',
            'Rate ',
            'Discount ',
            'Taxable Value'
        );
        $aligns = array('C', 'C', 'C', 'C', 'C', 'C', 'C', 'C');
        
        $this->create_table_header($pdf, $widths, $headers, $aligns);
        
        // Table content
        $pdf->SetFont('helvetica', '', 8);
        
        $counter = 1;
        
        foreach ($items as $item) {
            $product = $item->get_product();
            $hsn_code = '';
            $gst_rate = '5'; // Default GST rate
            
            // Safely get HSN code and GST rate
            if ($product) {
                $hsn_code = $invoice_generator->get_product_hsn_code($product);
                $product_gst_rate = $invoice_generator->get_product_gst_rate($product);
                if (!empty($product_gst_rate)) {
                    $gst_rate = $product_gst_rate;
                }
            }
            
            $price = $order->get_item_total($item, false, false);
            $quantity = $item->get_quantity();
            $discount = $item->get_subtotal() - $item->get_total();
            $line_total = $item->get_total();
            
            // Item row with proper alignment
            $pdf->Cell($widths[0], 6, $counter, 1, 0, 'C');
            
            // Description cell - using MultiCell for long descriptions
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->MultiCell($widths[1], 6, $item->get_name(), 1, 'L');
            $new_y = $pdf->GetY();
            $pdf->SetXY($x + $widths[1], $y);
            
            $pdf->Cell($widths[2], $new_y - $y, $hsn_code, 1, 0, 'C');
            $pdf->Cell($widths[3], $new_y - $y, $gst_rate.'%', 1, 0, 'C');
            $pdf->Cell($widths[4], $new_y - $y, $quantity, 1, 0, 'C');
            $pdf->Cell($widths[5], $new_y - $y, number_format($price, 2), 1, 0, 'R');
            $pdf->Cell($widths[6], $new_y - $y, number_format($discount, 2), 1, 0, 'R');
            $pdf->Cell($widths[7], $new_y - $y, number_format($line_total, 2), 1, 1, 'R');
            
            $counter++;
        }
        
        // Space before tax summary
        $pdf->Ln(6);
        
        // ========== TAX SUMMARY TABLE ==========
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'Tax Summary:', 0, 1, 'L');
        
        // Tax table - adjusted to match the same total width as goods table
        $tax_widths = array(30, 40, 40, 40, 40); // Width of columns
        $tax_headers = array(
            'Rate',
            'Taxable Value ',
            'IGST Amt ',
            'CGST Amt ',
            'SGST Amt '
        );
        $tax_aligns = array('C', 'C', 'C', 'C', 'C');
        
        $this->create_table_header($pdf, $tax_widths, $tax_headers, $tax_aligns);
        
        // Tax table content with proper alignment
        $pdf->SetFont('helvetica', '', 8);
        
        // Make sure tax_details has the expected structure
        if (isset($tax_details['rates']) && is_array($tax_details['rates'])) {
            foreach ($tax_details['rates'] as $rate => $values) {
                if (isset($values['taxable']) && $values['taxable'] > 0) {
                    $pdf->Cell($tax_widths[0], 6, $rate.'%', 1, 0, 'C');
                    $pdf->Cell($tax_widths[1], 6, number_format($values['taxable'], 2), 1, 0, 'R');
                    $pdf->Cell($tax_widths[2], 6, number_format(isset($values['igst']) ? $values['igst'] : 0, 2), 1, 0, 'R');
                    $pdf->Cell($tax_widths[3], 6, number_format(isset($values['cgst']) ? $values['cgst'] : 0, 2), 1, 0, 'R');
                    $pdf->Cell($tax_widths[4], 6, number_format(isset($values['sgst']) ? $values['sgst'] : 0, 2), 1, 1, 'R');
                }
            }
        }
        
        // Tax Summary Totals with bold font
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell($tax_widths[0], 6, 'Total', 1, 0, 'C');
        $pdf->Cell($tax_widths[1], 6, number_format(isset($tax_details['totals']['taxable']) ? $tax_details['totals']['taxable'] : 0, 2), 1, 0, 'R');
        $pdf->Cell($tax_widths[2], 6, number_format(isset($tax_details['totals']['igst']) ? $tax_details['totals']['igst'] : 0, 2), 1, 0, 'R');
        $pdf->Cell($tax_widths[3], 6, number_format(isset($tax_details['totals']['cgst']) ? $tax_details['totals']['cgst'] : 0, 2), 1, 0, 'R');
        $pdf->Cell($tax_widths[4], 6, number_format(isset($tax_details['totals']['sgst']) ? $tax_details['totals']['sgst'] : 0, 2), 1, 1, 'R');
        
        // Space before final amounts
        $pdf->Ln(6);
        
        // ========== INVOICE TOTALS SECTION ==========
        // Calculate final amounts
        $taxable_amount = isset($tax_details['totals']['taxable']) ? $tax_details['totals']['taxable'] : 0;
        $igst_amount = isset($tax_details['totals']['igst']) ? $tax_details['totals']['igst'] : 0;
        $cgst_amount = isset($tax_details['totals']['cgst']) ? $tax_details['totals']['cgst'] : 0;
        $sgst_amount = isset($tax_details['totals']['sgst']) ? $tax_details['totals']['sgst'] : 0;
        
        $total_tax = $igst_amount + $cgst_amount + $sgst_amount;
        $total_amount = $taxable_amount + $total_tax;
        $round_off = round($total_amount) - $total_amount;
        $final_amount = round($total_amount);
        
        // Right-aligned total amounts
        $pdf->SetFont('helvetica', 'B', 9);
        
        // Create a properly aligned right-side table for totals
        $table_start_x = 110; // Starting X position for the total table
        $total_label_width = 40;
        $total_value_width = 40;
        
        $pdf->Cell($table_start_x, 6, '', 0, 0); // Empty space on left
        $pdf->Cell($total_label_width, 6, 'Total Taxable Value', 1, 0, 'R');
        $pdf->Cell($total_value_width, 6, number_format($taxable_amount, 2), 1, 1, 'R');
        
        if ($cgst_amount > 0) {
            $pdf->Cell($table_start_x, 6, '', 0, 0);
            $pdf->Cell($total_label_width, 6, 'Add: CGST', 1, 0, 'R');
            $pdf->Cell($total_value_width, 6, number_format($cgst_amount, 2), 1, 1, 'R');
        }
        
        if ($sgst_amount > 0) {
            $pdf->Cell($table_start_x, 6, '', 0, 0);
            $pdf->Cell($total_label_width, 6, 'Add: SGST', 1, 0, 'R');
            $pdf->Cell($total_value_width, 6, number_format($sgst_amount, 2), 1, 1, 'R');
        }
        
        if ($igst_amount > 0) {
            $pdf->Cell($table_start_x, 6, '', 0, 0);
            $pdf->Cell($total_label_width, 6, 'Add: IGST', 1, 0, 'R');
            $pdf->Cell($total_value_width, 6, number_format($igst_amount, 2), 1, 1, 'R');
        }
        
        if ($round_off != 0) {
            $pdf->Cell($table_start_x, 6, '', 0, 0);
            $pdf->Cell($total_label_width, 6, 'Round Off', 1, 0, 'R');
            $pdf->Cell($total_value_width, 6, number_format($round_off, 2), 1, 1, 'R');
        }
        
        // Total amount - highlighted with fill
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell($table_start_x, 6, '', 0, 0);
        $pdf->Cell($total_label_width, 6, 'Total Invoice Amount', 1, 0, 'R', true);
        $pdf->Cell($total_value_width, 6, number_format($final_amount, 2), 1, 1, 'R', true);
        
        // ========== AMOUNT IN WORDS SECTION ==========
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 6, 'Amount in Words:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        
        try {
            $amount_in_words = $invoice_generator->number_to_words($final_amount);
            // Clean up double spaces
            $amount_in_words = preg_replace('/\s+/', ' ', $amount_in_words);
            $pdf->Cell(0, 6, ucfirst($amount_in_words) . ' Only', 0, 1, 'L');
        } catch (Exception $e) {
            $pdf->Cell(0, 6, 'Error converting amount to words', 0, 1, 'L');
        }
        
        // ========== DECLARATION SECTION ==========
        // Add a divider line before declaration
        $pdf->Ln(3);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(3);
        
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'Declaration:', 0, 1, 'L');
        
        // Create a border box for declaration
        $pdf->SetFont('helvetica', '', 9);
        
        if (!empty($declaration_text)) {
            // Remove "that" if present
            $edited_declaration = str_replace('that ', '', $declaration_text);
            $pdf->MultiCell(0, 5, $edited_declaration, 1, 'L');
        } else {
            $declaration_text = 'We declare this invoice shows actual price of goods described and all particulars are true and correct.';
            $pdf->MultiCell(0, 5, $declaration_text, 1, 'L');
        }
        
        // ========== SIGNATURE SECTION ==========
        $pdf->Ln(5);
        
        // Company name and signature
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'For ' . $company_name, 0, 1, 'R');
        
        // Add signature image if available
        if (!empty($signature_image_id)) {
            $signature_image_url = wp_get_attachment_url($signature_image_id);
            if ($signature_image_url) {
                // Calculate signature position (right-aligned)
                $signature_width = 40;
                $signature_x = $pdf->getPageWidth() - $signature_width - 10; // 10mm from right margin
                $signature_y = $pdf->GetY() + 5;
                
                try {
                    // Add the signature image with error handling
                    $pdf->Image($signature_image_url, $signature_x, $signature_y, $signature_width, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    
                    // Add space for signature
                    $pdf->Ln(25);
                } catch (Exception $e) {
                    // Log error and continue without signature
                    error_log('Error adding signature image: ' . $e->getMessage());
                    $pdf->Ln(15);
                }
            } else {
                // Add space for signature
                $pdf->Ln(15);
            }
        } else {
            // Add space for signature
            $pdf->Ln(15);
        }
        
        // Authorized Signatory text
        $pdf->Cell(0, 5, 'Authorized Signatory', 0, 1, 'R');
        
        return $pdf;
    }

    /**
     * Format price for PDF display
     *
     * @param float $price
     * @return string
     */
    private function format_price_for_pdf($price) {
        $currency_symbol = get_woocommerce_currency_symbol();
        $decimal_separator = wc_get_price_decimal_separator();
        $thousand_separator = wc_get_price_thousand_separator();
        $decimals = wc_get_price_decimals();
        
        return $currency_symbol . number_format($price, $decimals, $decimal_separator, $thousand_separator);
    }

    /**
     * Add order to existing PDF
     */
    public function add_order_to_pdf($pdf, $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Get invoice number
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        if (!$invoice_number) {
            $invoice_generator = new WC_GST_Invoice_Generator();
            $invoice_number = $invoice_generator->generate_invoice($order_id);
        }

        // Get company details
        $company_name = get_option('wgig_company_name');
        $company_address = get_option('wgig_company_address');
        $company_gstin = get_option('wgig_company_gstin');
        $company_state = get_option('wgig_company_state');
        $company_state_code = get_option('wgig_company_state_code');

        // Get customer details
        $customer_name = $order->get_formatted_billing_full_name();
        $customer_address = $order->get_formatted_billing_address();
        $customer_gstin = get_post_meta($order_id, '_billing_gstin', true);
        $customer_state = $order->get_billing_state();
        $customer_state_code = get_post_meta($order_id, '_billing_state_code', true);

        // Get order details
        $order_date = $order->get_date_created()->format('Y-m-d');
        $order_number = $order->get_order_number();
        $payment_method = $order->get_payment_method_title();
        $shipping_method = $order->get_shipping_method();

        // Get order items
        $items = $order->get_items();
        $total_tax = $order->get_total_tax();
        $total_amount = $order->get_total();

        // Add content to PDF
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'GST INVOICE', 0, 1, 'C');
        $pdf->Ln(10);

        // Company details
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, $company_name, 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, $company_address);
        $pdf->Cell(0, 5, 'GSTIN: ' . $company_gstin, 0, 1);
        $pdf->Cell(0, 5, 'State: ' . $company_state . ' (' . $company_state_code . ')', 0, 1);
        $pdf->Ln(10);

        // Invoice details
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Invoice Details', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(40, 5, 'Invoice Number:', 0);
        $pdf->Cell(0, 5, $invoice_number, 0, 1);
        $pdf->Cell(40, 5, 'Invoice Date:', 0);
        $pdf->Cell(0, 5, $order_date, 0, 1);
        $pdf->Cell(40, 5, 'Order Number:', 0);
        $pdf->Cell(0, 5, $order_number, 0, 1);
        $pdf->Ln(10);

        // Customer details
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Bill To:', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, $customer_name, 0, 1);
        $pdf->MultiCell(0, 5, $customer_address);
        if ($customer_gstin) {
            $pdf->Cell(0, 5, 'GSTIN: ' . $customer_gstin, 0, 1);
        }
        if ($customer_state_code) {
            $pdf->Cell(0, 5, 'State: ' . $customer_state . ' (' . $customer_state_code . ')', 0, 1);
        }
        $pdf->Ln(10);

        // Order items
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Order Items', 0, 1);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(100, 5, 'Description', 1);
        $pdf->Cell(30, 5, 'Quantity', 1);
        $pdf->Cell(30, 5, 'Price', 1);
        $pdf->Cell(30, 5, 'Total', 1, 1);
        $pdf->SetFont('helvetica', '', 10);

        foreach ($items as $item) {
            $pdf->Cell(100, 5, $item->get_name(), 1);
            $pdf->Cell(30, 5, $item->get_quantity(), 1);
            $pdf->Cell(30, 5, $this->format_price_for_pdf($item->get_total() / $item->get_quantity()), 1);
            $pdf->Cell(30, 5, $this->format_price_for_pdf($item->get_total()), 1, 1);
        }

        // Totals
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(160, 5, 'Total Tax:', 1);
        $pdf->Cell(30, 5, $this->format_price_for_pdf($total_tax), 1, 1);
        $pdf->Cell(160, 5, 'Total Amount:', 1);
        $pdf->Cell(30, 5, $this->format_price_for_pdf($total_amount), 1, 1);
        $pdf->Ln(10);

        // Payment details
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Payment Details', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(40, 5, 'Payment Method:', 0);
        $pdf->Cell(0, 5, $payment_method, 0, 1);
        if ($shipping_method) {
            $pdf->Cell(40, 5, 'Shipping Method:', 0);
            $pdf->Cell(0, 5, $shipping_method, 0, 1);
        }
        $pdf->Ln(10);

        // Terms and conditions
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Terms and Conditions', 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 5, get_option('wgig_terms_conditions'));
    }
}