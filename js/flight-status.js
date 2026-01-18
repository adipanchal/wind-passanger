// ==========================================
// Flight Status Indicators (Green/Yellow/Red)
// Show/hide based on availability percentage
// ==========================================
(function () {

    // Check if API URL is available
    if (typeof wpj_flight_obj === 'undefined') {
        return;
    }

    const apiUrl = wpj_flight_obj.api_url;

    /**
     * Update status indicator for a single flight card
     */
    function updateFlightStatus(card) {
        const postId = card.getAttribute('data-post-id');
        if (!postId) return;

        const stGreen = card.querySelector('.st-green');
        const stYellow = card.querySelector('.st-yellow');
        const stRed = card.querySelector('.st-red');

        // Skip if no status elements found
        if (!stGreen && !stYellow && !stRed) return;

        // Hide all first
        if (stGreen) stGreen.style.display = 'none';
        if (stYellow) stYellow.style.display = 'none';
        if (stRed) stRed.style.display = 'none';

        // Fetch availability from API
        fetch(`${apiUrl}?flight_id=${postId}`)
            .then(response => response.json())
            .then(data => {
                const total = parseInt(data.total) || 0;
                const available = parseInt(data.available) || 0;

                // Calculate percentage
                const percentage = total > 0 ? (available / total) * 100 : 0;

                // Show correct status
                if (available === 0) {
                    // No tickets left - RED
                    if (stRed) stRed.style.display = 'flex';
                } else if (percentage >= 75) {
                    // 75%+ available - GREEN
                    if (stGreen) stGreen.style.display = 'flex';
                } else {
                    // Below 75% - YELLOW
                    if (stYellow) stYellow.style.display = 'flex';
                }
            })
            .catch(() => {
                // On error, show green as default
                if (stGreen) stGreen.style.display = 'flex';
            });
    }

    /**
     * Update all flight cards in a container
     */
    function updateAllFlightStatuses(container = document) {
        const cards = container.querySelectorAll('.jet-listing-grid__item[data-post-id], .jet-calendar-week__day-event[data-post-id]');
        cards.forEach(card => updateFlightStatus(card));
    }

    // Run on page load
    document.addEventListener('DOMContentLoaded', () => {
        updateAllFlightStatuses();
    });

    // Observe for dynamically loaded content (JetEngine AJAX, lazy load, etc.)
    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    // Check if this node or its children have flight cards
                    if (node.classList && (node.classList.contains('jet-listing-grid__item') || node.classList.contains('jet-calendar-week__day-event'))) {
                        updateFlightStatus(node);
                    } else {
                        const cards = node.querySelectorAll ? node.querySelectorAll('.jet-listing-grid__item[data-post-id], .jet-calendar-week__day-event[data-post-id]') : [];
                        cards.forEach(card => updateFlightStatus(card));
                    }
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

})();
