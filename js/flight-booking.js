jQuery(document).ready(function ($) {

    if (typeof wpj_flight_obj === "undefined") {
        return;
    }

    const { api_url, reserve_url, release_url, texts } = wpj_flight_obj;
    let availableSeats = null; // Start as null
    let currentFlightId = null; // Dynamic flight ID
    let reservationTimer = null; // Timer for debouncing reservation calls
    let reservationExpiry = null; // Timestamp when reservation expires

    // Used for polling (if enabled)
    let pollInterval = 3000;
    let idleTimer = null;
    let pollTimer = null;

    // ==========================================
    // Helper: Get Flight ID from Form
    // ==========================================
    function getFlightIdFromForm($form) {
        let flightId = null;

        // 1. Try direct hidden field with numeric value only
        const possibleNames = ['flight_id', 'post_id', 'ticket_id', 'apartment_id', '__queried_post_id', 'stay'];
        for (const name of possibleNames) {
            const val = $form.find(`input[name="${name}"]`).val();
            if (val && /^\d+$/.test(val)) { // Only if purely numeric
                flightId = val;
                break;
            }
        }

        // 2. Try ticket_title field - may contain "tickets/24531" format
        if (!flightId) {
            const ticketTitle = $form.find('input[name="ticket_title"]').val();
            if (ticketTitle) {
                // Extract numeric ID from patterns like "tickets/24531" or just "24531"
                const match = ticketTitle.match(/(\d+)/);
                if (match) {
                    flightId = match[1];
                }
            }
        }

        // 3. Try popup parent container data attributes
        if (!flightId) {
            const $popup = $form.closest('.jet-popup, [data-popup-id], .elementor-popup-modal');
            if ($popup.length) {
                flightId = $popup.data('post-id') || $popup.data('flight-id') || $popup.attr('data-post-id');
            }
        }

        // 4. Try form data attribute
        if (!flightId) {
            flightId = $form.data('flight-id') || $form.data('post-id');
        }

        // 5. Try URL params as last resort
        if (!flightId) {
            const urlParams = new URLSearchParams(window.location.search);
            flightId = urlParams.get('flight_id') || urlParams.get('post_id');
        }

        return flightId ? parseInt(flightId, 10) : null;
    }

    // ==========================================
    // Helper: Find Elements
    // ==========================================
    function getElements() {
        // Priority 1: Find VISIBLE input (e.g. inside an open Popup)
        let $input = $('input[name="unit_number"]:visible');

        // Priority 2: Fallback to any input (if popup not yet fully animated or inline form)
        if (!$input.length) {
            $input = $("#unit_number");
        }
        if (!$input.length) {
            $input = $('input[name="unit_number"]');
        }

        // Status Msg
        // We try to find the msg relative to the found input first
        let $msg = $input
            .closest(".jet-form-builder-row")
            .find("#wpj-flight-status");
        if (!$msg.length) $msg = $("#wpj-flight-status");

        // Create if missing AND input exists
        if (!$msg.length && $input.length) {
            $input.after(
                '<span id="wpj-flight-status" style="display:block; margin-top:5px; font-weight:bold; font-size:12px;"></span>'
            );
            $msg = $input.next("#wpj-flight-status");
        }

        return { $input, $msg, $shortcode: $(".wpj-live-seats-count") };
    }


    // ==========================================
    // 2. Real-time Polling & Validation
    // ==========================================
    function checkAvailability(flightId) {
        if (document.hidden) return;
        if (!flightId) return;

        $.ajax({
            url: api_url,
            method: "GET",
            data: { flight_id: flightId, _wpnonce: wpj_flight_obj.nonce },
            success: function (res) {
                const newAvailable = parseInt(res.available, 10);
                // Update global var if changed or if it was initial
                if (newAvailable !== availableSeats || availableSeats === null) {
                    availableSeats = newAvailable;
                    currentFlightId = flightId; // Store current flight
                    updateAllUI();
                }
            },
            error: function (xhr, status, error) {
            },
        });
    }

    function updateAllUI() {
        const { $input, $msg, $shortcode } = getElements();

        // CRITICAL: Don't show anything if we haven't checked availability yet
        if (availableSeats === null) {
            if ($msg.length) $msg.text("Checking availability...").css("color", "#666");
            return;
        }

        // A. Update Shortcode (Independent)
        if ($shortcode.length) {
            $shortcode.text(availableSeats + " " + texts.seats_left);
        }

        // B. Validation & Locking Logic
        // --- NORMAL MODE ---
        if ($input.length) {
            $input.attr("max", availableSeats);

            // Sold Out State
            if (availableSeats <= 0) {
                $input.attr("max", 0).val(0).prop("disabled", true);
                if ($msg.length) $msg.text(`🚫 ${texts.no_seats}`).css("color", "red");
                $input
                    .closest("form")
                    .find('button[type="submit"]')
                    .prop("disabled", true);
            } else {
                // Available
                $input.prop("disabled", false);
                if ($msg.length)
                    $msg
                        .text(`✅ ${availableSeats} ${texts.seats_left}`)
                        .css("color", "green");

                // Auto-cap current value
                const currentVal = parseInt($input.val(), 10) || 0;
                if (currentVal > availableSeats) {
                    $input.val(availableSeats);
                }
                $input
                    .closest("form")
                    .find('button[type="submit"]')
                    .prop("disabled", false);
            }
        }
    }

    // ==========================================
    // 3. User Interaction Events (Delegated)
    // ==========================================

    // On Focus: Check availability for THIS flight
    $(document.body).on("focus", "#unit_number, input[name='unit_number']", function () {
        const $input = $(this);
        const $form = $input.closest("form");
        const flightId = getFlightIdFromForm($form);

        if (flightId) {
            checkAvailability(flightId);
        }
    });

    // Use 'body' delegation to handle inputs inside Popups created dynamically
    $(document.body).on(
        "input change keyup",
        "#unit_number, input[name='unit_number']",
        function () {


            const val = parseInt($(this).val(), 10) || 0;
            const $input = $(this);
            const $form = $input.closest("form");

            // Get or create message element next to THIS input
            let $msg = $input.siblings("#wpj-flight-status");
            if (!$msg.length) {
                $input.after(
                    '<span id="wpj-flight-status" style="display:block; margin-top:8px; font-weight:bold; font-size:14px; padding:8px; border-radius:4px; background:#f8f9fa;"></span>'
                );
                $msg = $input.siblings("#wpj-flight-status");
            }

            // CRITICAL: Wait for data before validating
            // If we don't know availability yet, don't show an error
            if (availableSeats === null) {
                if ($msg.length) {
                    $msg.text("Checking availability...").css({
                        "color": "#666",
                        "background-color": "#f8f9fa",
                        "border-left": "none"
                    }).show();
                }
                return;
            }

            // Don't auto-cap - let them type, but show error
            if (val > availableSeats) {
                // Show prominent error message
                $msg
                    .text(
                        `⚠️ Only ${availableSeats} tickets available! Please adjust your booking.`
                    )
                    .css({
                        color: "#dc3545",
                        "background-color": "#f8d7da",
                        "font-weight": "bold",
                        "font-size": "14px",
                        display: "block",
                        padding: "8px",
                        "border-left": "4px solid #dc3545"
                    })
                    .show();

                // Cap the calculated total_price field to max available
                correctCalculatedPrice($form, availableSeats);

                // Disable submit
                $form.find('button[type="submit"]').prop("disabled", true);
            } else if (val < 1) {
                $msg
                    .text("Please enter at least 1 ticket")
                    .css({
                        color: "#856404",
                        "background-color": "#fff3cd",
                        "font-weight": "normal",
                        "font-size": "13px",
                        display: "block",
                        padding: "8px",
                        "border-left": "4px solid #ffc107"
                    })
                    .show();
                $form.find('button[type="submit"]').prop("disabled", true);
            } else {
                // Valid input - restore success message
                $msg
                    .text(`✅ ${availableSeats} tickets available`)
                    .css({
                        color: "#155724",
                        "background-color": "#d4edda",
                        "font-weight": "bold",
                        "font-size": "13px",
                        display: "block",
                        padding: "8px",
                        "border-left": "4px solid #28a745"
                    })
                    .show();

                // Recalculate normally
                correctCalculatedPrice($form, val);

                // Enable submit
                $form.find('button[type="submit"]').prop("disabled", false);

                // Reserve seats for this user
                const flightId = getFlightIdFromForm($form);
                if (flightId) {
                    reserveSeats(flightId, val);
                }
            }
        }
    );

    // Helper: Correct calculated price field
    function correctCalculatedPrice($form, actualUnits) {
        const $totalPriceInput = $form.find('input[name="total_price"]');
        const $totalPriceDisplay = $form.find(
            ".jet-form-builder__calculated-field-val"
        );

        if ($totalPriceInput.length) {
            // Get the price per unit from data-formula or calculate from current value
            const $calcContainer = $totalPriceInput.closest(
                ".jet-form-builder__calculated-field"
            );
            const formula = $calcContainer.data("formula"); // e.g. "%price% * %unit_number%"

            // Try to extract price from the form
            const $priceField = $form.find('input[name="price"]');
            let pricePerUnit = 0;

            if ($priceField.length) {
                pricePerUnit = parseFloat($priceField.val()) || 0;
            } else {
                // Fallback: reverse-calculate from current total
                const currentTotal = parseFloat($totalPriceInput.val()) || 0;
                const currentUnits =
                    parseInt($form.find('input[name="unit_number"]').val(), 10) || 1;
                pricePerUnit = currentTotal / currentUnits;
            }

            // Calculate correct total (capped to actualUnits)
            const correctTotal = (pricePerUnit * actualUnits).toFixed(2);

            // Update both hidden input and display
            $totalPriceInput.val(correctTotal);
            if ($totalPriceDisplay.length) {
                $totalPriceDisplay.text(correctTotal);
            }
        }
    }

    // ==========================================
    // Seat Reservation Functions
    // ==========================================

    function reserveSeats(flightId, seats) {
        if (!flightId || seats < 1) return;

        // Debounce: Wait 500ms before calling API
        clearTimeout(reservationTimer);
        reservationTimer = setTimeout(() => {

            $.ajax({
                url: reserve_url,
                method: "POST",
                data: {
                    flight_id: flightId,
                    seats: seats,
                    _wpnonce: wpj_flight_obj.nonce
                },
                success: function (res) {
                    if (res.success) {
                        // Silently update expiry
                        reservationExpiry = Date.now() + (res.expires_in * 1000);
                    } else {
                        // Silently refresh availability on failure
                        checkAvailability(flightId);
                    }
                },
                error: function (xhr, status, error) {
                    // Silent error handling
                }
            });
        }, 500);
    }

    function releaseSeats(flightId) {
        if (!flightId) return;


        $.ajax({
            url: release_url,
            method: "POST",
            data: {
                flight_id: flightId,
                _wpnonce: wpj_flight_obj.nonce
            },
            success: function (res) {
                reservationExpiry = null;
            },
            error: function (xhr, status, error) {
            }
        });
    }

    // Prevent Submit (Delegated)
    $(document.body).on("submit", "form", function (e) {
        // Only check if this form contains our target input
        const $localInput = $(this).find("#unit_number, input[name='unit_number']");
        if (!$localInput.length) return;



        const currentVal = parseInt($localInput.val(), 10) || 0;
        if (currentVal > availableSeats) {
            e.preventDefault();
            e.stopPropagation();
            alert(`${texts.exceed_error} ${availableSeats}`);
            $localInput.val(availableSeats);
            return false;
        }
    });

    // ==========================================
    // 4. Popup Detection - Multiple Methods for Reliability
    // ==========================================

    // METHOD 1: MutationObserver - Detects when form becomes visible
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            // Check if any nodes were added
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) { // Element node
                    // Check if this node or its children contain our input
                    const $node = $(node);
                    const $inputs = $node.find('input[name="unit_number"]').add($node.filter('input[name="unit_number"]'));

                    $inputs.each(function () {
                        const $input = $(this);
                        const $form = $input.closest('form');
                        const flightId = getFlightIdFromForm($form);

                        if (flightId && $input.is(':visible')) {
                            // Create status message immediately
                            let $msg = $input.siblings("#wpj-flight-status");
                            if (!$msg.length) {
                                $input.after(
                                    '<span id="wpj-flight-status" style="display:block; margin-top:8px; font-weight:bold; font-size:14px; padding:8px; border-radius:4px; background:#f8f9fa;"></span>'
                                );
                                $msg = $input.siblings("#wpj-flight-status");
                            }

                            $msg.text("Checking availability...").css({
                                "color": "#666",
                                "background-color": "#f8f9fa"
                            }).show();

                            // Fetch availability
                            checkAvailability(flightId);

                            // Auto-reserve the initial value (usually 1)
                            const initialVal = parseInt($input.val(), 10) || 0;
                            if (initialVal > 0) {
                                reserveSeats(flightId, initialVal);
                            }
                        }
                    });
                }
            });
        });
    });

    // Start observing the body for added nodes
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // METHOD 2: JetPopup events (backup)
    $(document).on("jet-popup/show-event jet-popup/open-event elementor/popup/show", function (e, popupData) {
        setTimeout(() => {
            const $visibleInputs = $('input[name="unit_number"]:visible');
            $visibleInputs.each(function () {
                const $input = $(this);
                const $form = $input.closest('form');
                const flightId = getFlightIdFromForm($form);

                if (flightId) {
                    let $msg = $input.siblings("#wpj-flight-status");
                    if (!$msg.length) {
                        $input.after(
                            '<span id="wpj-flight-status" style="display:block; margin-top:8px; font-weight:bold; font-size:14px; padding:8px; border-radius:4px; background:#f8f9fa;"></span>'
                        );
                        $msg = $input.siblings("#wpj-flight-status");
                    }

                    $msg.text("Checking availability...").css({
                        "color": "#666",
                        "background-color": "#f8f9fa"
                    }).show();

                    checkAvailability(flightId);

                    // Auto-reserve the initial value (usually 1)
                    const initialVal = parseInt($input.val(), 10) || 0;
                    if (initialVal > 0) {
                        reserveSeats(flightId, initialVal);
                    }
                }
            });
        }, 200); // Increased timeout
    });

    // JetPopup close handler - release reservation
    $(document).on("jet-popup/hide-event", function (e, popupData) {
        if (currentFlightId) {
            releaseSeats(currentFlightId);
        }
    });

    // ==========================================
    // 5. Initial Check on Page Load
    // ==========================================
    // Check all visible forms on page load
    $('input[name="unit_number"]:visible').each(function () {
        const $form = $(this).closest('form');
        const flightId = getFlightIdFromForm($form);
        if (flightId) {
            checkAvailability(flightId);

            // Auto-reserve the initial value
            const initialVal = parseInt($(this).val(), 10) || 0;
            if (initialVal > 0) {
                reserveSeats(flightId, initialVal);
            }
        }
    });

    // ==========================================
    // 6. Booking Button Handler & Cleanup Logic
    // ==========================================

    let isBooking = false; // Flag to prevent cleanup during booking

    /**
     * Release seats synchronously (for booking button)
     */
    function releaseSeatsSync(flightId) {
        if (!flightId) return;


        // Synchronous AJAX to ensure it completes before form submits
        $.ajax({
            url: wpj_flight_obj.release_url,
            method: "POST",
            async: false, // MUST be synchronous
            data: {
                flight_id: flightId,
                _wpnonce: wpj_flight_obj.nonce
            },
            success: function (res) {
            },
            error: function (xhr, status, error) {
            }
        });
    }

    /**
     * Release on actual tab close (Beacon API)
     */
    function releaseOnExit() {
        // Don't release if user is in booking process
        if (isBooking) {
            return;
        }

        if (!currentFlightId || !reservationExpiry) return;


        // Use sendBeacon if available (reliable for unload)
        if (navigator.sendBeacon) {
            const data = new FormData();
            data.append('flight_id', currentFlightId);
            data.append('_wpnonce', wpj_flight_obj.nonce);

            const success = navigator.sendBeacon(wpj_flight_obj.release_url, data);
        } else {
            // Fallback for older browsers
            $.ajax({
                url: wpj_flight_obj.release_url,
                method: "POST",
                async: false, // Blocking call (deprecated but needed for fallback)
                data: {
                    flight_id: currentFlightId,
                    _wpnonce: wpj_flight_obj.nonce
                }
            });
        }
    }

    // ==========================================
    // Booking Form Submit Handler
    // ==========================================
    $(document).on('submit', '.flight-booking-form, form[data-form-id]', function (e) {
        const $form = $(this);
        const $input = $form.find('input[name="unit_number"]');

        // Only process if this form has our booking input
        if (!$input.length) return;


        // Set booking flag
        isBooking = true;

        // Release hold synchronously before form submits
        if (currentFlightId && reservationExpiry) {
            releaseSeatsSync(currentFlightId);

            // Clear reservation tracking
            reservationExpiry = null;
        }
    });

    // ==========================================
    // Tab Close Detection (ONLY on actual close)
    // ==========================================

    // Use pagehide (more reliable than beforeunload)
    window.addEventListener('pagehide', releaseOnExit);

    // Fallback for older browsers
    window.addEventListener('beforeunload', function (e) {
        releaseOnExit();
    });
});
