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
     * Update status indicator for a single flight card using PRE-FETCHED data
     * (Or fetch individually if no data provided - fallback)
     */
    function updateFlightStatus(card, preFetchedData = null) {
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

        // Helper to process data and update UI
        const processData = (data) => {
            const total = parseInt(data.total) || 0;
            const available = parseInt(data.available) || 0;
            const isExpired = data.is_expired === true;

            // If NOT expired, calculate and show status
            if (!isExpired) {
                const percentage = total > 0 ? (available / total) * 100 : 0;

                if (available === 0) {
                    if (stRed) stRed.style.display = 'flex';
                } else if (percentage >= 75) {
                    if (stGreen) stGreen.style.display = 'flex';
                } else {
                    if (stYellow) stYellow.style.display = 'flex';
                }
            }

            applyIconColorToDateBackground(card, isExpired);
        };

        // Use Pre-fetched data if available (Batch Mode)
        if (preFetchedData) {
            processData(preFetchedData);
            return;
        }

        // Fallback: Fetch individually (Legacy / Single Update)
        fetch(`${apiUrl}?flight_id=${postId}`)
            .then(response => response.json())
            .then(data => processData(data))
            .catch(() => {
                if (stGreen) stGreen.style.display = 'flex';
                applyIconColorToDateBackground(card, false);
            });
    }

    /**
     * Update all flight cards in a container (BATCH MODE)
     */
    function updateAllFlightStatuses(container = document) {
        const cards = container.querySelectorAll('.jet-listing-grid__item[data-post-id], .jet-calendar-week__day-event[data-post-id]');
        if (cards.length === 0) return;

        // 1. Collect IDs
        const flightIds = new Set();
        cards.forEach(card => {
            const pid = card.getAttribute('data-post-id');
            if (pid) flightIds.add(pid);
        });

        if (flightIds.size === 0) return;

        // 2. Batch Request
        const idsParam = Array.from(flightIds).join(',');

        // Single Request for all IDs
        fetch(`${apiUrl}?flight_ids=${idsParam}`)
            .then(res => res.json())
            .then(response => {
                if (!response.success || !response.data) return;

                const statusMap = response.data; // { 123: { total: 10, ... }, 124: { ... } }

                // 3. Update Elements
                cards.forEach(card => {
                    const pid = card.getAttribute('data-post-id');
                    if (statusMap[pid]) {
                        updateFlightStatus(card, statusMap[pid]);
                    }
                });
            })
            .catch(err => console.error('Flight Status Batch Error:', err));
    }

    // ==========================================
    // Calendar Specific Logic (User Provided + Integrated)
    // ==========================================

    // 1. Toggle Active State on Click
    document.addEventListener('click', function (e) {
        const day = e.target.closest('.jet-calendar-week__day.has-events');

        if (day) {
            // Remove active from peers
            document.querySelectorAll('.jet-calendar-week__day.has-events.active').forEach(el => {
                if (el !== day) el.classList.remove('active');
            });
            // Toggle active on clicked (or just add effectively since we cleared others)
            day.classList.add('active');
        } else {
            // Clicked outside a day?
            // Check if we are inside a popup. If so, DO NOT clear active class/close popup logic
            if (e.target.closest('.jet-popup') || e.target.closest('.jet-popup__inner') || e.target.closest('.jet-popup-content')) {
                return;
            }

            // If clicking generic "outside" (e.g. body overlay), clear active class
            document.querySelectorAll('.jet-calendar-week__day.has-events.active').forEach(el => {
                el.classList.remove('active');
            });
        }
    });

    // 2. Apply Status Color to Date Background
    function applyIconColorToDateBackground(card, isExpired) {
        // Find the parent day element if we are inside one
        const day = card.closest('.jet-calendar-week__day.has-events');
        if (!day) return;

        // Find the date element header
        const dateEl = day.querySelector('.jet-calendar-week__day-header .jet-calendar-week__day-date');
        if (!dateEl) return;

        if (isExpired) {
            // Apply Grey for expired/past flights
            dateEl.style.backgroundColor = '#d8d8d8'; // Grey
            return;
        }

        // Otherwise find the visible icon color
        let visibleColor = '';

        const stGreen = card.querySelector('.st-green');
        const stYellow = card.querySelector('.st-yellow');
        const stRed = card.querySelector('.st-red');

        if (stRed && getComputedStyle(stRed).display !== 'none') {
            const icon = stRed.querySelector('i');
            visibleColor = icon ? getComputedStyle(icon).color : 'red';
        } else if (stYellow && getComputedStyle(stYellow).display !== 'none') {
            const icon = stYellow.querySelector('i');
            visibleColor = icon ? getComputedStyle(icon).color : '#f39c12';
        } else if (stGreen && getComputedStyle(stGreen).display !== 'none') {
            const icon = stGreen.querySelector('i');
            visibleColor = icon ? getComputedStyle(icon).color : 'green';
        }

        if (visibleColor) {
            dateEl.style.backgroundColor = visibleColor;
        }
    }

    // Run on page load
    document.addEventListener('DOMContentLoaded', () => {
        updateAllFlightStatuses();
    });

    // Observe for dynamically loaded content (JetEngine AJAX, lazy load, etc.)
    const observer = new MutationObserver(mutations => {
        let needsUpdate = false;
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    if (node.classList && (node.classList.contains('jet-listing-grid__item') || node.classList.contains('jet-calendar-week__day-event'))) {
                        needsUpdate = true;
                    } else if (node.querySelectorAll) {
                        if (node.querySelectorAll('.jet-listing-grid__item[data-post-id], .jet-calendar-week__day-event[data-post-id]').length > 0) {
                            needsUpdate = true;
                        }
                    }
                }
            });
        });

        if (needsUpdate) {
            // Debounce usage if possible, but for now direct call
            updateAllFlightStatuses();
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

})();
