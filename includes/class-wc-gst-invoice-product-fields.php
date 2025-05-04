<?php
/**
 * WC_GST_Invoice_Product_Fields Class
 *
 * Handles the product GST related fields
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_GST_Invoice_Product_Fields {

    /**
     * Constructor
     */
    public function __construct() {
        // Add GST fields to product general tab
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_gst_product_fields'));
        
        // Save GST fields
        add_action('woocommerce_process_product_meta', array($this, 'save_gst_product_fields'));
        
        // Add GST fields to product variations
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_gst_variation_fields'), 10, 3);
        
        // Save GST fields for variations
        add_action('woocommerce_save_product_variation', array($this, 'save_gst_variation_fields'), 10, 2);
    }

    /**
     * Add GST fields to product general tab
     */
    public function add_gst_product_fields() {
        global $woocommerce, $post;
        
        echo '<div class="options_group">';
        
        // HSN Code
        woocommerce_wp_text_input(
            array(
                'id'          => '_hsn_code',
                'label'       => __('HSN Code', 'woo-gst-invoice'),
                'placeholder' => '',
                'desc_tip'    => 'true',
                'description' => __('Enter the HSN Code for this product.', 'woo-gst-invoice')
            )
        );
        
        // GST Rate
        woocommerce_wp_select(
            array(
                'id'          => '_gst_rate',
                'label'       => __('GST Rate (%)', 'woo-gst-invoice'),
                'options'     => array(
                    ''   => __('Select GST Rate', 'woo-gst-invoice'),
                    '0'  => '0%',
                    '0.1' => '0.1%',
                    '0.25' => '0.25%',
                    '1'  => '1%',
                    '3'  => '3%',
                    '5'  => '5%',
                    '12' => '12%',
                    '18' => '18%',
                    '28' => '28%',
                ),
                'desc_tip'    => 'true',
                'description' => __('Select the GST rate for this product.', 'woo-gst-invoice')
            )
        );
        
        echo '</div>';
    }

    /**
     * Save GST fields
     */
    public function save_gst_product_fields($post_id) {
        // HSN Code
        $hsn_code = isset($_POST['_hsn_code']) ? sanitize_text_field($_POST['_hsn_code']) : '';
        update_post_meta($post_id, '_hsn_code', $hsn_code);
        
        // GST Rate
        $gst_rate = isset($_POST['_gst_rate']) ? sanitize_text_field($_POST['_gst_rate']) : '';
        update_post_meta($post_id, '_gst_rate', $gst_rate);
    }

    /**
     * Add GST fields to product variations
     */
    public function add_gst_variation_fields($loop, $variation_data, $variation) {
        // HSN Code
        woocommerce_wp_text_input(
            array(
                'id'            => '_hsn_code[' . $variation->ID . ']',
                'label'         => __('HSN Code', 'woo-gst-invoice'),
                'placeholder'   => '',
                'desc_tip'      => true,
                'description'   => __('Enter the HSN Code for this variation.', 'woo-gst-invoice'),
                'value'         => get_post_meta($variation->ID, '_hsn_code', true),
                'wrapper_class' => 'form-row form-row-first',
            )
        );
        
        // GST Rate
        woocommerce_wp_select(
            array(
                'id'            => '_gst_rate[' . $variation->ID . ']',
                'label'         => __('GST Rate (%)', 'woo-gst-invoice'),
                'options'       => array(
                    ''   => __('Select GST Rate', 'woo-gst-invoice'),
                    '0'  => '0%',
                    '0.1' => '0.1%',
                    '0.25' => '0.25%',
                    '1'  => '1%',
                    '3'  => '3%',
                    '5'  => '5%',
                    '12' => '12%',
                    '18' => '18%',
                    '28' => '28%',
                ),
                'desc_tip'      => true,
                'description'   => __('Select the GST rate for this variation.', 'woo-gst-invoice'),
                'value'         => get_post_meta($variation->ID, '_gst_rate', true),
                'wrapper_class' => 'form-row form-row-last',
            )
        );
    }

    /**
     * Save GST fields for variations
     */
    public function save_gst_variation_fields($variation_id, $i) {
        // HSN Code
        $hsn_code = isset($_POST['_hsn_code'][$variation_id]) ? sanitize_text_field($_POST['_hsn_code'][$variation_id]) : '';
        update_post_meta($variation_id, '_hsn_code', $hsn_code);
        
        // GST Rate
        $gst_rate = isset($_POST['_gst_rate'][$variation_id]) ? sanitize_text_field($_POST['_gst_rate'][$variation_id]) : '';
        update_post_meta($variation_id, '_gst_rate', $gst_rate);
    }
}

// Initialize the class
new WC_GST_Invoice_Product_Fields();