jQuery(document).ready(function ($) {

    // Target the button by ID
    $(document).on('click', '#wind-cancel-reservation-btn', function (e) {
        e.preventDefault();

        var $btn = $(this);

        // Confirm User Action
        if (!confirm('Are you sure you want to cancel this reservation? This action cannot be undone.')) {
            return;
        }

        // Show loading state
        var originalText = $btn.text();
        $btn.text('Processing...').prop('disabled', true);

        // Get Post ID from URL (users reservation page: /reservation/123)
        // We assume the URL structure ends with the ID or is accessible
        // Use a localized variable if possible, or parse URL.
        // Actually, easiest is if the button has data-post-id attribute.
        // But user said "update the id which you gave", assuming just CSS ID?
        // Let's try to parse the URL for the ID since the user mentioned "/reservation/post_id"

        var urlPath = window.location.pathname;
        var pathParts = urlPath.replace(/\/$/, '').split('/');
        var postId = pathParts[pathParts.length - 1]; // Get last segment

        // Fallback: Check if wind_cancel_data has post_id (if we localize it)
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
                    alert(response.data);
                    window.location.reload(); // Reload to show new status
                } else {
                    alert('Error: ' + response.data);
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function () {
                alert('Network error. Please try again.');
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });
});
