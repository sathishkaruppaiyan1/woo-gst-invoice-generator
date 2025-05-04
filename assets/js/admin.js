jQuery(document).ready(function($) {
    // Media uploader for signature image
    if ($('#upload-signature-image').length) {
        $('#upload-signature-image').on('click', function(e) {
            e.preventDefault();
            
            var frame = wp.media({
                title: wgig_data.i18n.upload_title,
                multiple: false,
                library: {
                    type: 'image'
                },
                button: {
                    text: wgig_data.i18n.upload_button
                }
            });
            
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                
                $('#signature-image-id').val(attachment.id);
                
                $('#signature-image-preview').html('<img src="' + attachment.url + '" alt="Signature" style="max-width: 200px;" />');
                
                $('#upload-signature-image').hide();
                $('#remove-signature-image').show();
            });
            
            frame.open();
        });
    }
    
    // Remove signature image
    if ($('#remove-signature-image').length) {
        $('#remove-signature-image').on('click', function(e) {
            e.preventDefault();
            
            $('#signature-image-id').val('');
            $('#signature-image-preview').html('');
            
            $('#upload-signature-image').show();
            $('#remove-signature-image').hide();
        });
        
        // If there's no remove button initially, hide it
        if (!$('#signature-image-preview img').length) {
            $('#remove-signature-image').hide();
        }
    }
    
    // Bulk download invoices handler
    $(document).on('click', '.download-gst-invoice', function(e) {
        e.preventDefault();
        
        var order_id = $(this).data('order-id');
        var url = wgig_data.ajax_url + '?action=wgig_download_invoice&order_id=' + order_id + '&nonce=' + wgig_data.nonce;
        
        window.location.href = url;
    });
    
    // Add custom bulk action handler
    if ($('#doaction, #doaction2').length) {
        $(document).on('click', '#doaction, #doaction2', function(e) {
            var action = $('#bulk-action-selector-top, #bulk-action-selector-bottom').val();
            
            if (action === 'wgig_download_invoices' || action === 'wgig_generate_invoices') {
                var selected_orders = [];
                
                // Get selected order IDs
                $('input[name="post[]"]:checked').each(function() {
                    selected_orders.push($(this).val());
                });
                
                if (selected_orders.length === 0) {
                    alert(wgig_data.i18n.no_orders_selected || 'Please select at least one order.');
                    e.preventDefault();
                    return;
                }
                
                if (action === 'wgig_download_invoices') {
                    // Direct download through AJAX URL
                    var download_url = wgig_data.ajax_url + '?action=wgig_bulk_download_invoices&order_ids=' + 
                                       selected_orders.join(',') + '&nonce=' + wgig_data.nonce;
                    
                    window.location.href = download_url;
                    e.preventDefault();
                }
                // For generate_invoices, let the standard form submission happen
            }
        });
    }
    
    // Enable click on row actions
    if ($('.wgig_download_invoice, .wgig_generate_invoice').length) {
        $('.wgig_download_invoice, .wgig_generate_invoice').on('click', function(e) {
            e.stopPropagation(); // Prevent row click
        });
    }
});