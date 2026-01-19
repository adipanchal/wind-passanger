jQuery(function ($) {

    // 1. Safety Check: Verify if data object exists
    if (typeof JetTaxMap === 'undefined') {
        return;
    }

    const flightTypeMap = {};
    const locationMap  = {};

    // 2. Build ID → Name map
    if (Array.isArray(JetTaxMap.flightType)) {
        JetTaxMap.flightType.forEach(term => {
            flightTypeMap[String(term.term_id)] = term.name;
        });
    }

    if (Array.isArray(JetTaxMap.location)) {
        JetTaxMap.location.forEach(term => {
            locationMap[String(term.term_id)] = term.name;
        });
    }

    // 3. Main Processing Function
    function processColumns() {
        // Helper to process a specific selector with a map
        function replaceInColumn(selector, map) {
            $(selector).each(function () {
                const cell = $(this);
                // Mark as processed to avoid re-processing (though idempotent, text() access is DOM read)
                if (cell.attr('data-tax-fixed')) return;

                const raw = cell.text().trim();
                if (!raw) return;

                const ids = raw.split(',').map(id => id.trim());
                let hasChange = false;

                const names = ids.map(id => {
                    const name = map[String(id)];
                    if (name) {
                        hasChange = true;
                        return name;
                    }
                    return id;
                });

                if (hasChange) {
                    cell.text(names.join(', '));
                    cell.attr('data-tax-fixed', 'true'); // Flag to prevent redundant updates
                }
            });
        }

        replaceInColumn('td.column-flight-type, td.column-flight_type, td.column-flight_type_tax', flightTypeMap);
        replaceInColumn('td.column-location, td.column-flight-location, td.column-location_tax', locationMap);
    }

    // 4. Initial Run
    processColumns();

    // 5. MutationObserver for AJAX updates (e.g., pagination, smooth updates)
    const observerTarget = document.querySelector('body'); 
    // Using body to catch any dynamic table updates, typically .wp-list-table resides in #wpbody
    
    if (observerTarget) {
        const observer = new MutationObserver(function(mutations) {
            let shouldUpdate = false;
            for (let mutation of mutations) {
                if (mutation.addedNodes.length) {
                    shouldUpdate = true;
                    break;
                }
            }
            if (shouldUpdate) {
                // Simple verify if we have the table to avoid too many calls
                if ($('.wp-list-table').length) {
                    processColumns();
                }
            }
        });

        observer.observe(observerTarget, {
            childList: true,
            subtree: true
        });
    }

});
