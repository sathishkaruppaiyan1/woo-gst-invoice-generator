<?php
/**
 * WC_GST_Invoice_Admin Class
 *
 * Handles the admin functionality of the plugin
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add meta box to order page
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'), 10);
        
        // Add download button to order actions in order edit page
        add_action('woocommerce_order_actions', array($this, 'add_order_action'));
        
        // Process order action
        add_action('woocommerce_order_action_wgig_generate_invoice', array($this, 'process_order_action'));
        add_action('woocommerce_order_action_wgig_download_invoice', array($this, 'process_download_action'));
        
        // Add invoice column to orders page
        add_filter('manage_edit-shop_order_columns', array($this, 'add_invoice_column'), 20);
        
        // Populate invoice column
        add_action('manage_shop_order_posts_custom_column', array($this, 'populate_invoice_column'), 10, 2);
        
        // Add invoice actions to order row actions
        add_filter('woocommerce_admin_order_actions', array($this, 'add_order_row_actions'), 10, 2);
        
        // Add bulk action to orders page
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));
        
        // Process bulk actions
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'process_bulk_actions'), 10, 3);
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add download button to order view
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'add_download_button_to_order_view'));
        
        // Add download button to order edit page
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'add_download_button_to_order_edit'));
        
        // Check for image extensions
        $this->check_image_extensions();
        
        // Add custom CSS to admin head for order list buttons
        add_action('admin_head', array($this, 'add_custom_admin_css'));
    }
    
    /**
     * Add custom CSS for order list buttons
     */
    public function add_custom_admin_css() {
        ?>
        <style type="text/css">
            .widefat .column-invoice_number a.wgig-download-btn {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 3px;
                text-decoration: none;
                color: #0073aa;
                background: #f1f1f1;
                border: 1px solid #ddd;
            }
            .widefat .column-invoice_number a.wgig-download-btn:hover {
                background: #e6e6e6;
            }
            .wgig-download-icon {
                margin-right: 5px;
            }
            .order-actions .wgig_download_invoice,
            .order-actions .wgig_generate_invoice {
                display: inline-block !important;
                padding: 0 !important;
                height: 2em !important;
                width: 2em !important;
                overflow: hidden !important;
                position: relative !important;
                text-decoration: none !important;
                border-radius: 2px !important;
                background: #f7f7f7 !important;
                border: 1px solid #ccc !important;
                text-indent: -9999px !important;
                color: #0073aa !important;
            }
            .order-actions .wgig_download_invoice:after,
            .order-actions .wgig_generate_invoice:after {
                font-family: dashicons !important;
                speak: none !important;
                font-weight: 400 !important;
                font-variant: normal !important;
                text-transform: none !important;
                -webkit-font-smoothing: antialiased !important;
                margin: 0 !important;
                text-indent: 0 !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                text-align: center !important;
                line-height: 1.85 !important;
            }
            .order-actions .wgig_download_invoice:after {
                content: "\f316" !important;
            }
            .order-actions .wgig_generate_invoice:after {
                content: "\f313" !important;
            }
            /* Highlight invoice buttons */
            .order-actions .wgig_download_invoice:hover,
            .order-actions .wgig_generate_invoice:hover {
                background-color: #e6e6e6 !important;
            }
        </style>
        <?php
    }
    
    /**
     * Check if required extensions for image handling are available
     */
    public function check_image_extensions() {
        // Check if GD or Imagick extensions are available
        $has_gd = extension_loaded('gd');
        $has_imagick = extension_loaded('imagick');
        
        // If neither extension is available, show a notice
        if (!$has_gd && !$has_imagick) {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php _e('WooCommerce GST Invoice Generator: For best PDF results, please enable GD or Imagick PHP extension. Transparent PNG images will be converted to JPG as a fallback.', 'woo-gst-invoice'); ?></p>
                </div>
                <?php
            });
        }
    }

    /**
     * Add menu to admin dashboard
     */
    public function add_admin_menu() {
        add_menu_page(
            __('GST Invoice', 'woo-gst-invoice'),
            __('GST Invoice', 'woo-gst-invoice'),
            'manage_options',
            'wgig-settings',
            array($this, 'settings_page'),
            'dashicons-media-text',
            56
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('wgig_settings', 'wgig_company_name');
        register_setting('wgig_settings', 'wgig_company_address');
        register_setting('wgig_settings', 'wgig_company_phone');
        register_setting('wgig_settings', 'wgig_company_email');
        register_setting('wgig_settings', 'wgig_gstin');
        register_setting('wgig_settings', 'wgig_state_code');
        register_setting('wgig_settings', 'wgig_invoice_prefix');
        register_setting('wgig_settings', 'wgig_next_invoice_number', array('default' => 1));
        register_setting('wgig_settings', 'wgig_declaration_text');
        register_setting('wgig_settings', 'wgig_signature_image');
    }

    /**
     * Create settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('GST Invoice Generator Settings', 'woo-gst-invoice'); ?></h1>
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php settings_fields('wgig_settings'); ?>
                <?php do_settings_sections('wgig_settings'); ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Company Name', 'woo-gst-invoice'); ?></th>
                        <td><input type="text" name="wgig_company_name" value="<?php echo esc_attr(get_option('wgig_company_name')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                    <th scope="row"><?php _e('Company Address', 'woo-gst-invoice'); ?></th>
                        <td><textarea name="wgig_company_address" rows="3" class="large-text"><?php echo esc_textarea(get_option('wgig_company_address')); ?></textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Phone Number', 'woo-gst-invoice'); ?></th>
                        <td><input type="text" name="wgig_company_phone" value="<?php echo esc_attr(get_option('wgig_company_phone')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Email Address', 'woo-gst-invoice'); ?></th>
                        <td><input type="email" name="wgig_company_email" value="<?php echo esc_attr(get_option('wgig_company_email')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('GSTIN', 'woo-gst-invoice'); ?></th>
                        <td><input type="text" name="wgig_gstin" value="<?php echo esc_attr(get_option('wgig_gstin')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('State Code', 'woo-gst-invoice'); ?></th>
                        <td><input type="text" name="wgig_state_code" value="<?php echo esc_attr(get_option('wgig_state_code')); ?>" class="small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Invoice Number Prefix', 'woo-gst-invoice'); ?></th>
                        <td><input type="text" name="wgig_invoice_prefix" value="<?php echo esc_attr(get_option('wgig_invoice_prefix')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Next Invoice Number', 'woo-gst-invoice'); ?></th>
                        <td><input type="number" name="wgig_next_invoice_number" value="<?php echo esc_attr(get_option('wgig_next_invoice_number', 1)); ?>" class="small-text" min="1" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Declaration Text', 'woo-gst-invoice'); ?></th>
                        <td><textarea name="wgig_declaration_text" rows="3" class="large-text"><?php echo esc_textarea(get_option('wgig_declaration_text', 'We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.')); ?></textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Signature Image', 'woo-gst-invoice'); ?></th>
                        <td>
                            <?php
                            $image_id = get_option('wgig_signature_image');
                            if ($image_id) {
                                echo '<div id="signature-image-preview">';
                                echo wp_get_attachment_image($image_id, 'thumbnail');
                                echo '</div>';
                                echo '<button type="button" class="button" id="remove-signature-image">'.__('Remove Image', 'woo-gst-invoice').'</button>';
                                echo '<input type="hidden" name="wgig_signature_image" id="signature-image-id" value="'.$image_id.'">';
                            } else {
                                echo '<button type="button" class="button" id="upload-signature-image">'.__('Upload Image', 'woo-gst-invoice').'</button>';
                                echo '<input type="hidden" name="wgig_signature_image" id="signature-image-id" value="">';
                                echo '<div id="signature-image-preview"></div>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page or order pages
        if ($hook === 'toplevel_page_wgig-settings' || $hook === 'post.php' || $hook === 'edit.php') {
            wp_enqueue_media();
            wp_enqueue_style('wgig-admin-css', WGIG_PLUGIN_URL . 'assets/css/admin.css', array(), WGIG_VERSION);
            wp_enqueue_script('wgig-admin-js', WGIG_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WGIG_VERSION, true);
            
            // Pass some data to the script
            wp_localize_script('wgig-admin-js', 'wgig_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wgig_admin_js'),
                'i18n' => array(
                    'upload_title' => __('Choose Signature Image', 'woo-gst-invoice'),
                    'upload_button' => __('Use this image', 'woo-gst-invoice'),
                    'no_orders_selected' => __('Please select at least one order.', 'woo-gst-invoice')
                )
            ));
        }
    }

    /**
     * Add meta box to order page
     */
    public function add_order_meta_box() {
        add_meta_box(
            'wgig_invoice_meta_box',
            __('GST Invoice', 'woo-gst-invoice'),
            array($this, 'render_order_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Render meta box content
     */
    public function render_order_meta_box($post) {
        $order_id = $post->ID;
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        $invoice_date = get_post_meta($order_id, '_wgig_invoice_date', true);
        
        if ($invoice_number) {
            echo '<p><strong>'.__('Invoice Number:', 'woo-gst-invoice').'</strong> '.$invoice_number.'</p>';
            echo '<p><strong>'.__('Invoice Date:', 'woo-gst-invoice').'</strong> '.date_i18n(get_option('date_format'), strtotime($invoice_date)).'</p>';
            echo '<p><a href="'.admin_url('admin-ajax.php?action=wgig_download_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_download_invoice')).'" class="button button-primary">'.__('Download Invoice', 'woo-gst-invoice').'</a></p>';
        } else {
            echo '<p>'.__('No invoice has been generated yet.', 'woo-gst-invoice').'</p>';
            echo '<p><a href="'.admin_url('admin-post.php?action=wgig_generate_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_generate_invoice')).'" class="button">'.__('Generate Invoice', 'woo-gst-invoice').'</a></p>';
        }
    }

    /**
     * Add download button to order view
     */
    public function add_download_button_to_order_view($order) {
        $order_id = $order->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        if ($invoice_number) {
            echo '<p class="form-field form-field-wide">';
            echo '<a href="'.admin_url('admin-ajax.php?action=wgig_download_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_download_invoice')).'" class="button button-primary">'.__('Download GST Invoice', 'woo-gst-invoice').'</a>';
            echo '</p>';
        } else {
            echo '<p class="form-field form-field-wide">';
            echo '<a href="'.admin_url('admin-post.php?action=wgig_generate_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_generate_invoice')).'" class="button">'.__('Generate GST Invoice', 'woo-gst-invoice').'</a>';
            echo '</p>';
        }
    }

    /**
     * Add download button to order edit page
     */
    public function add_download_button_to_order_edit($order) {
        $order_id = $order->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        if ($invoice_number) {
            echo '<p class="form-field form-field-wide">';
            echo '<a href="' . esc_url(add_query_arg(array(
                'action' => 'download_gst_invoice',
                'order_id' => $order_id,
                'nonce' => wp_create_nonce('download_gst_invoice')
            ), admin_url('admin-ajax.php'))) . '" class="button button-primary">' . __('Download GST Invoice', 'woo-gst-invoice') . '</a>';
            echo '</p>';
        }
    }

    /**
     * Add custom action to WooCommerce order actions
     */
    public function add_order_action($actions) {
        global $theorder;
        
        // Check if order exists
        if (!$theorder) {
            return $actions;
        }
        
        $order_id = $theorder->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        if ($invoice_number) {
            $actions['wgig_download_invoice'] = __('Download GST Invoice', 'woo-gst-invoice');
        } else {
            $actions['wgig_generate_invoice'] = __('Generate GST Invoice', 'woo-gst-invoice');
        }
        
        return $actions;
    }

    /**
     * Process order action
     */
    public function process_order_action($order) {
        $order_id = $order->get_id();
        
        $invoice_generator = new WC_GST_Invoice_Generator();
        $invoice_number = $invoice_generator->generate_invoice($order_id);
        
        // Add notice
        if ($invoice_number) {
            WC_Admin_Notices::add_custom_notice('wgig_invoice_generated', sprintf(__('GST Invoice #%s generated successfully.', 'woo-gst-invoice'), $invoice_number));
        } else {
            WC_Admin_Notices::add_custom_notice('wgig_invoice_error', __('Failed to generate GST Invoice.', 'woo-gst-invoice'));
        }
    }
    
    /**
     * Process download action from order actions
     */
    public function process_download_action($order) {
        $order_id = $order->get_id();
        $pdf_generator = new WC_GST_Invoice_PDF();
        $pdf_generator->generate_and_download_pdf($order_id);
    }

    /**
     * Add invoice column to orders page
     */
    public function add_invoice_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add after order status column
            if ($key === 'order_status') {
                $new_columns['invoice_number'] = __('GST Invoice', 'woo-gst-invoice');
            }
        }
        
        return $new_columns;
    }

    /**
     * Populate invoice column
     */
    public function populate_invoice_column($column, $post_id) {
        if ($column === 'invoice_number') {
            $invoice_number = get_post_meta($post_id, '_wgig_invoice_number', true);
            
            if ($invoice_number) {
                echo '<a href="'.admin_url('admin-ajax.php?action=wgig_download_invoice&order_id='.$post_id.'&nonce='.wp_create_nonce('wgig_download_invoice')).'" class="wgig-download-btn" title="'.__('Download Invoice', 'woo-gst-invoice').'">';
                echo '<svg class="wgig-download-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 16L16 11H13V4H11V11H8L12 16Z" fill="#0073aa"/>
                    <path d="M20 18H4V11H2V18C2 19.103 2.897 20 4 20H20C21.103 20 22 19.103 22 18V11H20V18Z" fill="#0073aa"/>
                </svg>';
                echo ' '.$invoice_number;
                echo '</a>';
            } else {
                echo '<a href="'.admin_url('admin-post.php?action=wgig_generate_invoice&order_id='.$post_id.'&nonce='.wp_create_nonce('wgig_generate_invoice')).'" class="button" title="'.__('Generate Invoice', 'woo-gst-invoice').'">';
                echo __('Generate', 'woo-gst-invoice');
                echo '</a>';
            }
        }
    }

    /**
     * Add row actions to the order list
     * 
     * @param array $actions
     * @param WC_Order $order
     * @return array
     */
    public function add_order_row_actions($actions, $order) {
        $order_id = $order->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        if ($invoice_number) {
            $actions['wgig_download_invoice'] = array(
                'url'    => admin_url('admin-ajax.php?action=wgig_download_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_download_invoice')),
                'name'   => __('Download GST Invoice', 'woo-gst-invoice'),
                'action' => 'wgig_download_invoice',
            );
        } else {
            $actions['wgig_generate_invoice'] = array(
                'url'    => admin_url('admin-post.php?action=wgig_generate_invoice&order_id='.$order_id.'&nonce='.wp_create_nonce('wgig_generate_invoice')),
                'name'   => __('Generate GST Invoice', 'woo-gst-invoice'),
                'action' => 'wgig_generate_invoice',
            );
        }
        
        return $actions;
    }

    /**
     * Add bulk action to orders page
     */
    public function add_bulk_actions($actions) {
        $actions['wgig_generate_invoices'] = __('Generate GST Invoices', 'woo-gst-invoice');
        $actions['wgig_download_invoices'] = __('Download GST Invoices', 'woo-gst-invoice');
        return $actions;
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'wgig_generate_invoices' && $action !== 'wgig_download_invoices') {
            return $redirect_to;
        }
        
        $invoice_generator = new WC_GST_Invoice_Generator();
        $processed_ids = array();
        
        foreach ($post_ids as $post_id) {
            if ($action === 'wgig_generate_invoices') {
                $invoice_number = $invoice_generator->generate_invoice($post_id);
                if ($invoice_number) {
                    $processed_ids[] = $post_id;
                }
            } else {
                // For download action, generate invoice if it doesn't exist
                $invoice_number = get_post_meta($post_id, '_wgig_invoice_number', true);
                if (!$invoice_number) {
                    $invoice_number = $invoice_generator->generate_invoice($post_id);
                }
                if ($invoice_number) {
                    $processed_ids[] = $post_id;
                }
            }
        }
        
        if ($action === 'wgig_generate_invoices') {
            $redirect_to = add_query_arg(array(
                'wgig_generated' => count($processed_ids),
                'wgig_total' => count($post_ids),
            ), $redirect_to);
        } else if ($action === 'wgig_download_invoices' && !empty($processed_ids)) {
            // Redirect to download page
            $redirect_to = admin_url('admin-ajax.php?action=wgig_bulk_download_invoices&order_ids=' . implode(',', $processed_ids) . '&nonce=' . wp_create_nonce('wgig_bulk_download_invoices'));
        }
        
        return $redirect_to;
    }

    /**
     * Display admin notices
     */
    public function admin_notices() {
        global $pagenow;
        
        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
            if (isset($_GET['wgig_generated']) && isset($_GET['wgig_total'])) {
                $generated = intval($_GET['wgig_generated']);
                $total = intval($_GET['wgig_total']);
                
                if ($generated === $total) {
                    echo '<div class="notice notice-success is-dismissible"><p>'.sprintf(__('%d GST Invoices generated successfully.', 'woo-gst-invoice'), $generated).'</p></div>';
                } else {
                    echo '<div class="notice notice-warning is-dismissible"><p>'.sprintf(__('%d out of %d GST Invoices generated successfully.', 'woo-gst-invoice'), $generated, $total).'</p></div>';
                }
            }
        }
    }

    /**
     * Display invoice number in order details
     */
    public function display_invoice_number($order) {
        $order_id = $order->get_id();
        $invoice_number = get_post_meta($order_id, '_wgig_invoice_number', true);
        
        if ($invoice_number) {
            echo '<p><strong>' . __('GST Invoice Number:', 'woo-gst-invoice') . '</strong> ' . esc_html($invoice_number) . '</p>';
        }
    }
}