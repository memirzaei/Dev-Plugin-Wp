/**
 * FPS System Health — refresh button
 *
 * Expects window.FPSHealth = { ajaxUrl, nonce, i18n: { refreshing, refreshed, error, refreshBtn } }
 */
(function ($) {
	"use strict";

	function setButtonBusy($btn, busy, label) {
		$btn.prop("disabled", !!busy);
		// Preserve SVG icon if present; only update text node after the icon.
		var $icon = $btn.children("svg").first();
		if ($icon.length) {
			// Remove only text nodes / non-svg children, then append label.
			$btn.contents().filter(function () {
				return this.nodeType === 3 || (this.nodeType === 1 && this.tagName !== "SVG");
			}).remove();
			$btn.append(document.createTextNode(" " + label));
		} else {
			$btn.text(label);
		}
	}

	function showStatus($el, msg, isError) {
		if (!$el || !$el.length) {
			return;
		}
		$el.text(msg || "").css("color", isError ? "#dc2626" : "");
	}

	$(function () {
		var $btn = $("#fps-health-refresh");
		if (!$btn.length) {
			return;
		}

		$btn.on("click", function (e) {
			e.preventDefault();

			if (typeof FPSHealth === "undefined" || !FPSHealth.ajaxUrl) {
				window.alert("FPSHealth config missing – reload the page.");
				return;
			}

			var $cards = $("#fps-health-cards");
			var $updated = $("#fps-health-last-updated");
			var i18n = FPSHealth.i18n || {};

			setButtonBusy($btn, true, i18n.refreshing || "…");

			$.ajax({
				url: FPSHealth.ajaxUrl,
				type: "POST",
				dataType: "json",
				data: {
					action: "fps_health_refresh",
					nonce: FPSHealth.nonce
				},
				timeout: 45000
			})
				.done(function (res) {
					if (res && res.success && res.data && typeof res.data.html === "string") {
						$cards.html(res.data.html);
						showStatus(
							$updated,
							res.data.message || i18n.refreshed || "OK",
							false
						);
					} else {
						var msg =
							(res && res.data && res.data.message) ||
							i18n.error ||
							"Error";
						showStatus($updated, msg, true);
					}
				})
				.fail(function (xhr) {
					var msg = i18n.error || "Error";
					if (xhr && xhr.status === 403) {
						msg = (i18n.error || "Error") + " (403)";
					} else if (xhr && xhr.status === 0) {
						msg = (i18n.error || "Error") + " (network)";
					} else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
					showStatus($updated, msg, true);
					if (window.console && console.warn) {
						console.warn("FPS health refresh failed", xhr && xhr.status, xhr && xhr.responseText);
					}
				})
				.always(function () {
					setButtonBusy($btn, false, i18n.refreshBtn || "Refresh");
				});
		});
	});
})(jQuery);
