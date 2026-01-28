jQuery(document).ready(function ($) {

    // Target the button by ID
    $(document).on('click', '#wind-cancel-reservation-btn', function (e) {
        e.preventDefault();

        var $btn = $(this);

        // Show loading state immediately (No "Confirm" check)
        var originalText = $btn.text();
        $btn.text('Processing...').prop('disabled', true);

        // Get Post ID from URL
        var urlPath = window.location.pathname;
        var pathParts = urlPath.replace(/\/$/, '').split('/');
        var postId = pathParts[pathParts.length - 1]; 

        // Fallback: Check if wind_cancel_data has post_id
        if (typeof wind_cancel_data !== 'undefined' && wind_cancel_data.post_id) {
            postId = wind_cancel_data.post_id;
        }

        $.ajax({
            url: wind_cancel_data.ajax_url,
            type: 'POST',
            data: {
                action: 'wind_cancel_reservation',
                nonce: wind_cancel_data.nonce,
                post_id: postId
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data); // Success alert kept
                    window.location.reload(); 
                } else {
                    alert('Error: ' + response.data); // Error alert kept
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function () {
                alert('Network error. Please try again.'); // Network alert kept
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });
});