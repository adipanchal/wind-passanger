jQuery(document).ready(function ($) {
  // Debug helper
  function wpjLog(msg) {
    console.log("[WPJ-Flight]", msg);
  }

  if (typeof wpj_flight_obj === "undefined") {
    wpjLog("Error: wpj_flight_obj not found. Script localized incorrectly?");
    return;
  }

  const { api_url, reserve_url, release_url, texts } = wpj_flight_obj;
  let availableSeats = null; // Start as null
  let pollInterval = 3000;
  let idleTimer = null;
  let pollTimer = null;
  let isLockedByVoucher = false;
  let currentFlightId = null; // Dynamic flight ID
  let reservationTimer = null; // Timer for debouncing reservation calls
  let reservationExpiry = null; // Timestamp when reservation expires

  // We no longer cache $unitInput globally because it might appear/disappear in Popups.
  // We will find it dynamically when needed or on specific events.

  // ==========================================
  // Helper: Get Flight ID from Form
  // ==========================================
  function getFlightIdFromForm($form) {
    let flightId = null;

    // Try multiple common field names
    const possibleNames = ['flight_id', 'post_id', 'ticket_id', 'ticket_title', 'apartment_id'];

    for (const name of possibleNames) {
      const val = $form.find(`input[name="${name}"]`).val();
      if (val) {
        flightId = val;
        wpjLog(`Found flight ID in field "${name}": ${val}`);
        break;
      }
    }

    // Try data attribute
    if (!flightId) {
      flightId = $form.data('flight-id') || $form.data('post-id');
    }

    // Try URL params
    if (!flightId) {
      const urlParams = new URLSearchParams(window.location.search);
      flightId = urlParams.get('flight_id') || urlParams.get('post_id');
    }

    // Log all hidden inputs for debugging
    if (!flightId) {
      wpjLog("Could not find flight ID. Hidden inputs in form:");
      $form.find('input[type="hidden"]').each(function () {
        wpjLog(`  - ${$(this).attr('name')}: ${$(this).val()}`);
      });
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
  // 1. Voucher Mode Logic (Run Validation)
  // ==========================================
  function applyVoucherLock() {
    const voucherData = wpj_flight_obj.voucher;
    const { $input, $msg } = getElements();

    if (voucherData && voucherData.passengers) {
      isLockedByVoucher = true;
      const seatsNeeded = parseInt(voucherData.passengers, 10);

      if ($input.length) {
        $input
          .val(seatsNeeded)
          .attr("readonly", true)
          .attr("max", seatsNeeded)
          .attr("min", seatsNeeded)
          .addClass("locked-by-voucher")
          .css({
            "background-color": "#f0f0f0",
            cursor: "not-allowed",
            "border-color": "#ccc",
          });
      }

      if ($msg.length) {
        $msg.text(`🔒 ${texts.locked} (${seatsNeeded})`).css("color", "#333");
      }
      return true;
    }
    return false;
  }

  // Initial Attempt
  applyVoucherLock();

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
        wpjLog("API Error: " + error);
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
    if (isLockedByVoucher) {
      // Voucher Mode: ENFORCE LOCK & VALIDATE
      const voucherData = wpj_flight_obj.voucher;
      const seatsNeeded = parseInt(voucherData.passengers, 10);

      // 1. Enforce Lock State (Every poll, in case DOM reset)
      if ($input.length && !$input.hasClass("locked-by-voucher")) {
        $input
          .val(seatsNeeded)
          .attr("readonly", true)
          .attr("max", seatsNeeded)
          .attr("min", seatsNeeded)
          .addClass("locked-by-voucher")
          .css({
            "background-color": "#f0f0f0",
            cursor: "not-allowed",
            "border-color": "#ccc",
          });

        // Ensure value is correct (if form reset somehow)
        if (parseInt($input.val()) !== seatsNeeded) {
          $input.val(seatsNeeded);
        }
      }

      // 2. Validate Availability
      if (seatsNeeded > availableSeats) {
        const errText = `🚫 ${texts.no_seats} (Needs ${seatsNeeded})`;
        if ($msg.length) $msg.text(errText).css("color", "red");
        if ($input.length) {
          $input
            .closest("form")
            .find('button[type="submit"]')
            .prop("disabled", true);
        }
      } else {
        // Valid State in Voucher Mode
        if ($msg.length)
          $msg.text(`🔒 ${texts.locked} (${seatsNeeded})`).css("color", "#333");
        if ($input.length) {
          $input
            .closest("form")
            .find('button[type="submit"]')
            .prop("disabled", false);
        }
      }
      return;
    }

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
      wpjLog(`User focused on booking form for Flight ID: ${flightId}`);
      checkAvailability(flightId);
    }
  });

  // Use 'body' delegation to handle inputs inside Popups created dynamically
  $(document.body).on(
    "input change keyup",
    "#unit_number, input[name='unit_number']",
    function () {
      if (isLockedByVoucher) return;

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
    if (!flightId || seats < 1 || isLockedByVoucher) return;

    // Debounce: Wait 500ms before calling API
    clearTimeout(reservationTimer);
    reservationTimer = setTimeout(() => {
      wpjLog(`Reserving ${seats} seats for flight ${flightId}...`);

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
            wpjLog(`✅ Reserved ${res.reserved} seats. Expires in ${res.expires_in}s`);
            reservationExpiry = Date.now() + (res.expires_in * 1000);

            // Show reservation confirmation
            const { $msg } = getElements();
            if ($msg.length) {
              $msg.text(`🔒 ${seats} ${texts.reserved} (10 min hold)`)
                .css({
                  color: "#0c5460",
                  "background-color": "#d1ecf1",
                  "font-weight": "bold",
                  "border-left": "4px solid #17a2b8"
                }).show();
            }
          } else {
            wpjLog("❌ Reservation failed: " + res.message);
            console.error("Full API Response:", res);
            // Refresh availability
            checkAvailability(flightId);
          }
        },
        error: function (xhr, status, error) {
          wpjLog("❌ Reserve API Error: " + error);
          console.error("XHR:", xhr);
          console.error("Status:", status);
          console.error("Response Text:", xhr.responseText);
        }
      });
    }, 500);
  }

  function releaseSeats(flightId) {
    if (!flightId) return;

    wpjLog(`Releasing reservation for flight ${flightId}...`);

    $.ajax({
      url: release_url,
      method: "POST",
      data: {
        flight_id: flightId,
        _wpnonce: wpj_flight_obj.nonce
      },
      success: function (res) {
        wpjLog("Reservation released");
        reservationExpiry = null;
      },
      error: function (xhr, status, error) {
        wpjLog("Release API Error: " + error);
      }
    });
  }

  // Prevent Submit (Delegated)
  $(document.body).on("submit", "form", function (e) {
    // Only check if this form contains our target input
    const $localInput = $(this).find("#unit_number, input[name='unit_number']");
    if (!$localInput.length) return;

    if (isLockedByVoucher) return;

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
  // 4. JetPopup Integration + MutationObserver
  // ==========================================

  // MutationObserver: Watch for input appearing in DOM
  if (wpj_flight_obj.voucher) {
    wpjLog("Voucher Mode: Starting MutationObserver...");
    const observer = new MutationObserver(function (mutations) {
      const $input = $('input[name="unit_number"]:visible');
      if ($input.length && !$input.hasClass("locked-by-voucher")) {
        wpjLog("MutationObserver: Found unlocked input! Locking now...");
        const seatsNeeded = parseInt(wpj_flight_obj.voucher.passengers, 10);
        $input
          .val(seatsNeeded)
          .attr("readonly", true)
          .attr("max", seatsNeeded)
          .attr("min", seatsNeeded)
          .addClass("locked-by-voucher")
          .css({
            "background-color": "#f0f0f0",
            cursor: "not-allowed",
            "border-color": "#ccc",
          });
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true,
    });
  }

  // JetPopup event handler
  $(document).on("jet-popup/show-event", function (e, popupData) {
    wpjLog("JetPopup show-event triggered!");
    // Force immediate check
    setTimeout(() => {
      // Find any unit_number inputs in the newly opened popup
      const $visibleInputs = $('input[name="unit_number"]:visible');
      $visibleInputs.each(function () {
        const $form = $(this).closest('form');
        const flightId = getFlightIdFromForm($form);
        if (flightId) {
          wpjLog(`Checking availability for Flight ID: ${flightId}`);
          checkAvailability(flightId);
        }
      });

      applyVoucherLock();
    }, 100);
  });

  // JetPopup close handler - release reservation
  $(document).on("jet-popup/hide-event", function (e, popupData) {
    wpjLog("JetPopup hide-event triggered - releasing reservation...");
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
    
    wpjLog("Releasing hold for booking submission...");
    
    // Synchronous AJAX to ensure it completes before form submits
    $.ajax({
      url: wpj_flight_obj.release_url,
      method: "POST",
      async: false, // MUST be synchronous
      data: {
        flight_id: flightId,
        _wpnonce: wpj_flight_obj.nonce
      },
      success: function(res) {
        wpjLog("✅ Hold released for booking");
      },
      error: function(xhr, status, error) {
        wpjLog("⚠️ Could not release hold: " + error);
      }
    });
  }
  
  /**
   * Release on actual tab close (Beacon API)
   */
  function releaseOnExit() {
    // Don't release if user is in booking process
    if (isBooking) {
      wpjLog("Skipping cleanup - booking in progress");
      return;
    }
    
    if (!currentFlightId || !reservationExpiry) return;

    wpjLog("Tab closing - releasing hold via Beacon");

    // Use sendBeacon if available (reliable for unload)
    if (navigator.sendBeacon) {
      const data = new FormData();
      data.append('flight_id', currentFlightId);
      data.append('_wpnonce', wpj_flight_obj.nonce);
      
      const success = navigator.sendBeacon(wpj_flight_obj.release_url, data);
      if(success) wpjLog("✅ Beacon sent for cleanup");
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
  $(document).on('submit', '.flight-booking-form, form[data-form-id]', function(e) {
    const $form = $(this);
    const $input = $form.find('input[name="unit_number"]');
    
    // Only process if this form has our booking input
    if (!$input.length) return;
    
    wpjLog("📝 Booking form submitted");
    
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
  window.addEventListener('beforeunload', function(e) {
    releaseOnExit();
  });
});
