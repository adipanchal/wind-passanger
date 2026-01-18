// ==========================================
// Dynamic passenger Title generate
// ==========================================
(function () {
    // 1. The Core Function: Updates titles based on row index
    function updatePassengerTitles(container) {
        if (!container) return;

        const rows = container.querySelectorAll('.jet-form-builder-repeater__row');
        rows.forEach((row, index) => {
            let title = row.querySelector('.passenger-title');

            if (!title) {
                title = document.createElement('h2');
                title.className = 'wp-block-heading passenger-title';
                title.style.marginBottom = '10px';
                row.prepend(title);
            }
            title.textContent = 'Passenger ' + (index + 1);
        });
    }

    // 2. The Observer: Re-runs logic when rows are added/removed
    function attachRepeaterObserver(container) {
        if (!container || container.dataset.observed === '1') return;

        container.dataset.observed = '1';
        const observer = new MutationObserver(() => updatePassengerTitles(container));
        observer.observe(container, { childList: true });

        // Run once immediately on attach
        updatePassengerTitles(container);
    }

    // 3. The Unified Listener: Handles Normal Page AND Popups
    // JetFormBuilder triggers this whenever a form is initialized
    jQuery(document).on('jet-form-builder/after-init', function (event, $scope) {
        // Find all repeaters within the initialized form
        const repeaters = $scope[0].querySelectorAll('.jet-form-builder-repeater__items');

        repeaters.forEach(container => {
            attachRepeaterObserver(container);
        });
    });

    // 4. Fallback: For cases where the form might already be there
    document.addEventListener('DOMContentLoaded', function () {
        const repeaters = document.querySelectorAll('.jet-form-builder-repeater__items');
        repeaters.forEach(container => attachRepeaterObserver(container));
    });

})();
