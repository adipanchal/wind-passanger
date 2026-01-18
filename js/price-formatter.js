// ==========================================
// Convert "." To "," In Price
// ==========================================
(function () {

    function convertPrices(root = document) {
        root.querySelectorAll('.pt-price').forEach(el => {
            el.innerHTML = el.innerHTML.replace(/(\d+)\.(\d+)/g, '$1,$2');
        });
    }

    // Run on page load
    document.addEventListener('DOMContentLoaded', () => {
        convertPrices();
    });

    // Observe for popups / dynamic content (Elementor)
    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) {
                    convertPrices(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

})();
