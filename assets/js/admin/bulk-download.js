jQuery(document).ready(function($) {
    // Handle bulk download form submission
    $('#wgig-bulk-download-form').on('submit', function(e) {
        e.preventDefault();
        
        // Show loading state
        $('#wgig-bulk-download-submit').prop('disabled', true);
        $('.wgig-bulk-download-message').remove();
        $('#wgig-bulk-download-form').before('<div class="wgig-bulk-download-message notice notice-info"><p>Processing your request...</p></div>');
        
        // Get form data
        var formData = new FormData(this);
        formData.append('action', 'wgig_bulk_download_invoices');
        formData.append('nonce', wgigBulkDownload.nonce);
        
        // Submit form via AJAX
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    // Create download link
                    var link = document.createElement('a');
                    link.href = response.data.url;
                    link.download = response.data.filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Show success message
                    $('.wgig-bulk-download-message').remove();
                    $('#wgig-bulk-download-form').before('<div class="wgig-bulk-download-message notice notice-success"><p>Download started successfully!</p></div>');
                } else {
                    // Show error message
                    $('.wgig-bulk-download-message').remove();
                    $('#wgig-bulk-download-form').before('<div class="wgig-bulk-download-message notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                // Show error message
                $('.wgig-bulk-download-message').remove();
                $('#wgig-bulk-download-form').before('<div class="wgig-bulk-download-message notice notice-error"><p>An error occurred while processing your request.</p></div>');
            },
            complete: function() {
                // Reset form state
                $('#wgig-bulk-download-submit').prop('disabled', false);
            }
        });
    });
}); 