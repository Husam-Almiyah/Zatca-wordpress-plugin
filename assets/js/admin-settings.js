jQuery(document).ready(function($) {
    // Handle Generate CSR button click
    $('#zatca-generate-csr-btn').on('click', function() {
        var $button = $(this);
        var $resultDiv = $('#zatca-cert-management-result');

        $button.prop('disabled', true).text('Generating CSR...');
        $resultDiv.html('<div class="notice notice-info"><p>Generating CSR and Private Key...</p></div>');

        $.ajax({
            url: zatca_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'zatca_generate_csr',
                nonce: zatca_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    // Optionally, refresh the page or update CSR/Private Key fields
                    location.reload(); 
                } else {
                    $resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $resultDiv.html('<div class="notice notice-error"><p>An unknown error occurred during CSR generation.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Generate CSR');
            }
        });
    });

    // Handle Request Compliance Certificate button click
    $('#zatca-request-compliance-cert-btn').on('click', function() {
        var $button = $(this);
        var $resultDiv = $('#zatca-cert-management-result');

        $button.prop('disabled', true).text('Requesting Compliance Certificate...');
        $resultDiv.html('<div class="notice notice-info"><p>Requesting compliance certificate from ZATCA...</p></div>');

        $.ajax({
            url: zatca_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'zatca_request_compliance_certificate',
                nonce: zatca_admin_ajax.nonce,
            },
            success: function(response) {
                if (response.success) {
                    $resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    location.reload(); 
                } else {
                    $resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $resultDiv.html('<div class="notice notice-error"><p>An unknown error occurred during compliance certificate request.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Request Compliance Certificate');
            }
        });
    });

    // Handle Request Production Certificate button click
    $('#zatca-request-production-cert-btn').on('click', function() {
        var $button = $(this);
        var $resultDiv = $('#zatca-cert-management-result');

        $button.prop('disabled', true).text('Requesting Production Certificate...');
        $resultDiv.html('<div class="notice notice-info"><p>Requesting production certificate from ZATCA using compliance credentials...</p></div>');

        $.ajax({
            url: zatca_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'zatca_request_production_certificate',
                nonce: zatca_admin_ajax.nonce,
            },
            success: function(response) {
                if (response.success) {
                    $resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    location.reload(); 
                } else {
                    $resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $resultDiv.html('<div class="notice notice-error"><p>An unknown error occurred during production certificate request.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Request Production Certificate');
            }
        });
    });

    // Handle Activate Certificate button click
    $('.zatca-activate-cert-btn').on('click', function() {
        var $button = $(this);
        var certId = $button.data('cert-id');
        var $row = $button.closest('tr');

        if (!confirm('Are you sure you want to activate this certificate? This will deactivate any other active certificate for the same environment.')) {
            return;
        }

        $button.prop('disabled', true).text('Activating...');
        $row.css('opacity', '0.5');

        $.ajax({
            url: zatca_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'zatca_activate_certificate',
                nonce: zatca_admin_ajax.nonce,
                cert_id: certId
            },
            success: function(response) {
                if (response.success) {
                    alert('Certificate activated successfully!');
                    location.reload(); 
                } else {
                    alert('Error activating certificate: ' + response.data.message);
                }
            },
            error: function() {
                alert('An unknown error occurred during certificate activation.');
            },
            complete: function() {
                $button.prop('disabled', false).text('Activate');
                $row.css('opacity', '1');
            }
        });
    });

    // Handle Delete Certificate button click
    $('.zatca-delete-cert-btn').on('click', function() {
        var $button = $(this);
        var certId = $button.data('cert-id');
        var $row = $button.closest('tr');

        if (!confirm('Are you sure you want to delete this certificate? This action cannot be undone.')) {
            return;
        }

        $button.prop('disabled', true).text('Deleting...');
        $row.css('opacity', '0.5');

        $.ajax({
            url: zatca_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'zatca_delete_certificate',
                nonce: zatca_admin_ajax.nonce,
                cert_id: certId
            },
            success: function(response) {
                if (response.success) {
                    alert('Certificate deleted successfully!');
                    $row.remove(); // Remove row from table
                } else {
                    alert('Error deleting certificate: ' + response.data.message);
                }
            },
            error: function() {
                alert('An unknown error occurred during certificate deletion.');
            },
            complete: function() {
                $button.prop('disabled', false).text('Delete');
                $row.css('opacity', '1');
            }
        });

        // Handle Submit Invoice button click
        $('#zatca-submit-invoice-btn').on('click', function() {
            var $button = $(this);
            var $resultDiv = $('#zatca-submission-result');
            var orderId = parseInt($('#zatca_submission_order_id').val(), 10);

            if (!orderId) {
                $resultDiv.html('<div class="notice notice-error"><p>Please enter a valid Order ID.</p></div>');
                return;
            }

            $button.prop('disabled', true).text('Submitting Invoice...');
            $resultDiv.html('<div class="notice notice-info"><p>Submitting invoice to ZATCA...</p></div>');

            $.ajax({
                url: zatca_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'zatca_submit_invoice',
                    order_id: orderId,
                    nonce: zatca_admin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data || {};
                        var html = '<div class="notice notice-success"><p>' + (data.message || 'Invoice submitted successfully.') + '</p></div>';
                        
                        if (data.status) {
                            html += '<p><strong>Status:</strong> ' + data.status + '</p>';
                        }
                        if (data.irn) {
                            html += '<p><strong>IRN:</strong> ' + data.irn + '</p>';
                        }
                        if (data.pih) {
                            html += '<p><strong>PIH:</strong> ' + data.pih + '</p>';
                        }
                        if (data.qr_code) {
                            html += '<p><strong>QR Code:</strong> ' + data.qr_code + '</p>';
                        }
                        if (data.errors && data.errors.length) {
                            html += '<div class="notice notice-error"><p><strong>Errors:</strong></p><ul>';
                            for (var i = 0; i < data.errors.length; i++) {
                                html += '<li>' + data.errors[i] + '</li>';
                            }
                            html += '</ul></div>';
                        }
                        
                        $resultDiv.html(html);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Invoice submission failed.';
                        $resultDiv.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                    }
                },
                error: function() {
                    $resultDiv.html('<div class="notice notice-error"><p>An unknown error occurred during invoice submission.</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Submit Invoice');
                }
            });
        });
    });
});
