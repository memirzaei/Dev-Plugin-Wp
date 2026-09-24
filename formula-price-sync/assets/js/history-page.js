/**
 * Formula Price Sync – History page CSV export handler.
 * Uses fetch + Blob to trigger a native file download.
 */
(function () {
	'use strict';

	function ready() {
		var exportBtn   = document.getElementById('fps-export-csv');
		var filters     = document.getElementById('fps-csv-filters');
		var confirmBtn  = document.getElementById('fps-confirm-export-csv');
		var statusSpan  = document.getElementById('fps-csv-status');

		if (!exportBtn || !filters || !confirmBtn || !statusSpan) {
			return;
		}

		exportBtn.addEventListener('click', function () {
			filters.style.display = (filters.style.display === 'block') ? 'none' : 'block';
		});

		confirmBtn.addEventListener('click', function () {
			var formData = new FormData();
			formData.append('action', 'fps_export_history_csv');
			formData.append('nonce', FPSHistory.nonce);
			formData.append('start_date', document.getElementById('fps_csv_start_date').value);
			formData.append('end_date', document.getElementById('fps_csv_end_date').value);
			formData.append('trigger_type', document.getElementById('fps_csv_trigger_type').value);
			formData.append('product_id', document.getElementById('fps_csv_product_id').value);

			statusSpan.textContent = FPSHistory.i18n.exporting;
			statusSpan.style.display = 'inline';
			confirmBtn.disabled = true;

			fetch(FPSHistory.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			}).then(function (response) {
				// The server sends CSV with Content-Type: text/csv on success.
				var contentType = response.headers.get('Content-Type') || '';
				if (contentType.indexOf('text/csv') !== -1 || contentType.indexOf('application/octet-stream') !== -1) {
					return response.blob().then(function (blob) {
						var url = URL.createObjectURL(blob);
						var a = document.createElement('a');
						a.href = url;
						a.download = 'fps-price-history.csv';
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
						URL.revokeObjectURL(url);
						statusSpan.textContent = FPSHistory.i18n.done;
						statusSpan.style.color = 'var(--fps-color-success)';
					});
				}
				// Error responses are JSON.
				return response.json().then(function (data) {
					statusSpan.textContent = (data && data.data && data.data.message) ? data.data.message : FPSHistory.i18n.error;
					statusSpan.style.color = 'var(--fps-color-error)';
				});
			}).catch(function () {
				statusSpan.textContent = FPSHistory.i18n.error;
				statusSpan.style.color = 'var(--fps-color-error)';
			}).finally(function () {
				setTimeout(function () {
					statusSpan.style.display = 'none';
					confirmBtn.disabled = false;
				}, 3000);
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ready);
	} else {
		ready();
	}
})();