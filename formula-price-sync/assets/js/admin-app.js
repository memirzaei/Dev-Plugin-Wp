/**
 * Formula Price Sync – Admin JS
 * Dynamic gold vs currency fields + live Rial preview + bulk sync.
 */
(function ($) {
	'use strict';

	function parseNum(sel) {
		var v = parseFloat($(sel).val());
		return isFinite(v) && v >= 0 ? v : 0;
	}

	function getDisplayUnit() {
		return (typeof FPS !== 'undefined' && typeof FPS.displayUnit !== 'undefined') ? parseInt(FPS.displayUnit, 10) : 2;
	}

	function toPersianNum(s) {
		var map = { '0':'۰','1':'۱','2':'۲','3':'۳','4':'۴','5':'۵','6':'۶','7':'۷','8':'۸','9':'۹' };
		return String(s).replace(/[0-9]/g, function(d){ return map[d]; });
	}

	function formatMoney(n) {
		// Always show both units; primary determined by displayUnit setting.
		var rial = Math.round(n);
		var toman = Math.round(n / 10);
		var rialStr, tomanStr;
		try {
			rialStr = toPersianNum(new Intl.NumberFormat('en-US').format(rial));
			tomanStr = toPersianNum(new Intl.NumberFormat('en-US').format(toman));
		} catch (e) {
			rialStr = toPersianNum(rial.toLocaleString());
			tomanStr = toPersianNum(toman.toLocaleString());
		}
		if (getDisplayUnit() === 1) {
			return rialStr + ' ریال (' + tomanStr + ' تومان)';
		}
		return tomanStr + ' تومان (' + rialStr + ' ریال)';
	}

	window.fpsRecalcPreview = function(unit){
		if (typeof unit !== 'undefined') {
			FPS.displayUnit = parseInt(unit, 10);
		}
		// Dual-unit display is self-contained in formatMoney; clear legacy unit span.
		var $unit = $('#fps-preview-unit');
		if ($unit.length) {
			$unit.text('');
		}
		calculatePreview();
	};

	function i18n(key, fallback) {
		if (typeof FPS !== 'undefined' && FPS.i18n && FPS.i18n[key]) {
			return FPS.i18n[key];
		}
		return fallback || '';
	}

	function getRateForSource() {
		var $el = $('#fps-current-rate');
		if (!$el.length) {
			return 0;
		}
		var source = $('#_fps_source_type').val() || 'gold_18k';
		var key = source;
		if (source === 'currency') {
			key = $('#_fps_currency_code').val() || 'usd';
		} else if (source === 'custom_formula') {
			key = 'gold_18k';
		}
		var r = parseFloat($el.data(key));
		if (!isFinite(r) || r <= 0) {
			r = parseFloat($el.data('rate'));
		}
		return isFinite(r) && r > 0 ? r : 0;
	}

	function updateBaseFieldLabel() {
		var source = $('#_fps_source_type').val() || 'gold_18k';
		var $row = $('#_fps_base_foreign_price').closest('.form-field, .fps-base-amount-row');
		var $label = $row.find('label');
		var $desc = $row.find('.description');
		var currency = $('#_fps_currency_code').val() || 'usd';
		var currencyLabel = currency === 'eur' ? 'یورو' : 'دلار';

		if (source === 'currency') {
			$label.html(i18n('base_price_label', 'قیمت پایه ارزی') + ' (' + currencyLabel + ')');
			if ($desc.length) {
				$desc.text(i18n('base_price_desc', 'قیمت کالا به واحد ارز (مثلاً ۷۰۰ دلار برای گوشی).'));
			}
			$('#_fps_base_foreign_price').attr('placeholder', currency === 'eur' ? 'مثلاً 749' : 'مثلاً 700');
		} else if (source === 'custom_formula') {
			$label.html(i18n('base_custom_label', 'مقدار پایه / وزن'));
			if ($desc.length) {
				$desc.text(i18n('base_custom_desc', 'مقدار {weight} در فرمول سفارشی.'));
			}
		} else {
			$label.html(i18n('weight_label', 'وزن (گرم)'));
			if ($desc.length) {
				$desc.text(i18n('weight_desc', 'وزن قطعه به گرم.'));
			}
			$('#_fps_base_foreign_price').attr('placeholder', 'مثلاً 10');
		}

		var $profitRow = $('#_fps_profit_percent').closest('.form-field, .fps-profit-field');
		var $profitLabel = $profitRow.find('label');
		if ($profitLabel.length) {
			if (source === 'currency') {
				$profitLabel.html(i18n('profit_currency', 'درصد سود روی قیمت تبدیل‌شده'));
			} else {
				$profitLabel.html(i18n('profit_gold', 'درصد سود فروشنده'));
			}
		}
	}

	function updateFormulaHint() {
		var source = $('#_fps_source_type').val() || 'gold_18k';
		var $hint = $('#fps-formula-hint');
		if (!$hint.length) {
			return;
		}
		var text = '';
		if (source === 'currency') {
			text = i18n('formula_currency', 'فرمول ارز: قیمت ریالی = قیمت پایه × نرخ ارز | نهایی = قیمت ریالی × (۱ + ٪سود) + هزینه ثابت');
		} else if (source === 'custom_formula') {
			text = i18n('formula_custom', 'فرمول سفارشی با متغیرهای مجاز اجرا می‌شود.');
		} else {
			text = i18n('formula_gold', 'فرمول طلا: ارزش خام = وزن × نرخ | اجرت | سود | مالیات فقط روی (اجرت+سود) | جمع نهایی');
		}
		$hint.text(text);
	}

	function calculatePreview() {
		var $preview = $('#fps-price-preview');
		if (!$preview.length) {
			return;
		}

		var sourceType = $('#_fps_source_type').val() || 'gold_18k';
		var baseAmount = parseNum('#_fps_base_foreign_price');
		var wage = parseNum('#_fps_wage_percent');
		var profit = parseNum('#_fps_profit_percent');
		var taxRaw = $('#_fps_tax_percent').val();
		var tax = taxRaw === '' ? 9 : parseNum('#_fps_tax_percent');
		var fixedFee = parseNum('#_fps_fixed_fee');
		var rate = getRateForSource();

		if (baseAmount <= 0 || rate <= 0) {
			$preview.text('—');
			return;
		}

		var total = 0;
		if (sourceType === 'currency') {
			var baseRial = baseAmount * rate;
			total = baseRial * (1 + profit / 100) + fixedFee;
		} else if (sourceType === 'custom_formula') {
			total = baseAmount * rate + fixedFee;
		} else {
			var rawGold = baseAmount * rate;
			var wageAmt = rawGold * (wage / 100);
			var profitAmt = (rawGold + wageAmt) * (profit / 100);
			var taxAmt = (wageAmt + profitAmt) * (tax / 100);
			total = rawGold + wageAmt + profitAmt + taxAmt + fixedFee;
		}

		if (!isFinite(total) || total < 0) {
			$preview.text('—');
			return;
		}
		$preview.text(formatMoney(total));
	}

	function toggleFieldsBySource() {
		var source = $('#_fps_source_type').val() || 'gold_18k';
		var $wrap = $('.fps-pricing-fields');
		$wrap.removeClass('fps-source-currency fps-source-gold fps-source-custom');

		if (source === 'currency') {
			$wrap.addClass('fps-source-currency');
			$('.fps-pricing-fields .fps-currency-code-row').show();
			$('.fps-pricing-fields .fps-gold-only-field').hide();
			$('.fps-pricing-fields .fps-custom-formula-row').hide();
		} else if (source === 'custom_formula') {
			$wrap.addClass('fps-source-custom');
			$('.fps-pricing-fields .fps-currency-code-row').hide();
			$('.fps-pricing-fields .fps-gold-only-field').show();
			$('.fps-pricing-fields .fps-custom-formula-row').show();
		} else {
			$wrap.addClass('fps-source-gold');
			$('.fps-pricing-fields .fps-currency-code-row').hide();
			$('.fps-pricing-fields .fps-gold-only-field').show();
			$('.fps-pricing-fields .fps-custom-formula-row').hide();
		}

		updateBaseFieldLabel();
		updateFormulaHint();
		calculatePreview();
	}

	function toggleVariationFields($block) {
		if (!$block || !$block.length) {
			return;
		}
		var $source = $block.find('select[name*="_fps_source_type"], .fps-var-source-type').first();
		var source = $source.val() || 'gold_18k';
		var $currencyRow = $block.find('.fps-currency-code-row');
		var $goldOnly = $block.find('.fps-gold-only-field');
		var $taxField = $block.find('.fps-var-tax-percent-field');
		var $taxMode = $block.find('.fps-currency-tax-mode-row');
		var $custom = $block.find('.fps-custom-formula-row');
		var $baseRow = $block.find('.fps-base-amount-row');
		var $baseInput = $block.find('.fps-var-base-amount, input[name*="_fps_base_foreign_price"]').first();
		var $profitLabel = $block.find('.fps-profit-field label').first();
		var $hint = $block.find('.fps-var-formula-hint');

		$block.removeClass('fps-source-currency fps-source-gold fps-source-custom');

		if (source === 'currency') {
			$block.addClass('fps-source-currency');
			$currencyRow.show();
			$goldOnly.hide();
			$taxField.show();
			$taxMode.show();
			$custom.hide();
			var curr = $block.find('.fps-var-currency-code, select[name*="_fps_currency_code"]').val() || 'usd';
			var currLabel = curr === 'eur' ? 'یورو' : 'دلار';
			$baseRow.find('label').html(i18n('base_price_label', 'قیمت پایه ارزی') + ' (' + currLabel + ')');
			if ($baseInput.length) {
				$baseInput.attr('placeholder', curr === 'eur' ? 'مثلاً 749' : 'مثلاً 700');
			}
			if ($profitLabel.length) {
				$profitLabel.html(i18n('profit_currency', 'درصد سود روی قیمت تبدیل‌شده'));
			}
			if ($hint.length) {
				$hint.text(i18n('formula_currency', ''));
			}
		} else if (source === 'custom_formula') {
			$block.addClass('fps-source-custom');
			$currencyRow.hide();
			$goldOnly.show();
			$taxField.hide();
			$taxMode.hide();
			$custom.show();
			$baseRow.find('label').html(i18n('base_custom_label', 'مقدار پایه / وزن'));
			if ($profitLabel.length) {
				$profitLabel.html(i18n('profit_gold', 'درصد سود فروشنده'));
			}
			if ($hint.length) {
				$hint.text(i18n('formula_custom', ''));
			}
		} else {
			$block.addClass('fps-source-gold');
			$currencyRow.hide();
			$goldOnly.show();
			$taxField.hide();
			$taxMode.hide();
			$custom.hide();
			$baseRow.find('label').html(i18n('weight_label', 'وزن (گرم)'));
			if ($baseInput.length) {
				$baseInput.attr('placeholder', 'مثلاً 10');
			}
			if ($profitLabel.length) {
				$profitLabel.html(i18n('profit_gold', 'درصد سود فروشنده'));
			}
			if ($hint.length) {
				$hint.text(i18n('formula_gold', ''));
			}
		}
	}

	function toggleAllVariations() {
		$('.fps-variation-pricing-fields').each(function () {
			toggleVariationFields($(this));
		});
	}

	function bulkSync() {
		var $btn = $('#fps-bulk-sync-btn');
		var $spin = $('#fps-bulk-spinner');
		var $prog = $('#fps-bulk-progress');
		var $bar = $('#fps-bulk-bar');
		var $status = $('#fps-bulk-status');
		var $result = $('#fps-bulk-result');

		if (!$btn.length || typeof FPS === 'undefined') {
			return;
		}

		$btn.prop('disabled', true);
		$spin.show();
		$prog.show();
		$bar.css('width', '15%');
		$status.text(i18n('calculating', '...'));
		$result.empty();

		var cats = $('#fps-bulk-product-cats').val() || [];
		var tags = $('#fps-bulk-product-tags').val() || [];

		$.post(FPS.ajaxUrl, {
			action: 'fps_bulk_sync',
			nonce: FPS.nonce,
			product_cats: cats,
			product_tags: tags
		})
			.done(function (res) {
				$bar.css('width', '100%');
				if (res && res.success) {
					$status.text(res.data && res.data.message ? res.data.message : 'OK');
					$result.html('<div class="notice notice-success inline"><p>' + (res.data && res.data.message ? res.data.message : 'OK') + '</p></div>');
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : i18n('error', 'Error');
					$status.text(msg);
					$result.html('<div class="notice notice-error inline"><p>' + msg + '</p></div>');
				}
			})
			.fail(function () {
				$bar.css('width', '100%');
				$status.text(i18n('error', 'Error'));
			})
			.always(function () {
				$btn.prop('disabled', false);
				$spin.hide();
			});
	}

	function fetchLogs() {
		if (typeof FPS === 'undefined' || !$('#fps-logs-table-body').length) {
			return;
		}
		$.post(FPS.ajaxUrl, {
			action: 'fps_fetch_logs',
			nonce: FPS.nonce
		}).done(function (res) {
			if (res && res.success && res.data && res.data.html) {
				$('#fps-logs-table-body').html(res.data.html);
			}
		});
	}

	$(function () {
		toggleFieldsBySource();
		toggleAllVariations();

		// Initialize selectWoo for bulk sync filters
		if (typeof $.fn.selectWoo !== 'undefined') {
			$('#fps-bulk-product-cats, #fps-bulk-product-tags').selectWoo({
				width: '100%',
				placeholder: $(this).data('placeholder') || '',
				allowClear: true,
				multiple: true,
			});
		}

		$(document).on(
			'change input',
			'#_fps_source_type, #_fps_currency_code, #_fps_base_foreign_price, #_fps_wage_percent, #_fps_profit_percent, #_fps_tax_percent, #_fps_fixed_fee',
			function () {
				if ($(this).is('#_fps_source_type') || $(this).is('#_fps_currency_code')) {
					toggleFieldsBySource();
				} else {
					calculatePreview();
				}
			}
		);

		$(document).on(
			'change',
			'.fps-variation-pricing-fields select[name*="_fps_source_type"], .fps-variation-pricing-fields select[name*="_fps_currency_code"], .fps-var-source-type, .fps-var-currency-code',
			function () {
				toggleVariationFields($(this).closest('.fps-variation-pricing-fields'));
			}
		);

		$(document).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
			toggleAllVariations();
		});

	$(document).on('click', '#fps-bulk-sync-btn', function (e) {
			e.preventDefault();
			bulkSync();
		});

		$(document).on('click', '.fps-unit-btn', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var unit = parseInt($btn.data('unit'), 10);
			var $container = $('.fps-unit-switcher');
			var nonce = $container.data('nonce');
			var ajaxUrl = $container.data('ajax-url');
			var hidden = $('#fps_display_unit_hidden');
			if (!hidden.length) {
				hidden = $('<input type="hidden" name="fps_options[display_unit]" id="fps_display_unit_hidden">').appendTo($container);
			}
			var data = new FormData();
			data.append('action', 'fps_save_display_unit');
			data.append('nonce', nonce);
			data.append('display_unit', unit);
			fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res.success) {
						hidden.val(unit);
						$container.find('.fps-unit-btn').toggleClass('active', false);
						$btn.addClass('active');
						if (typeof FPS !== 'undefined') {
							FPS.displayUnit = unit;
						}
						if (window.fpsRecalcPreview) window.fpsRecalcPreview(unit);
					} else {
						console.error('Failed to save display unit', res);
					}
				})
				.catch(function(err){
					console.error('AJAX error', err);
				});
		});

		$(document).on('click', '#fps-refresh-logs', function (e) {
			e.preventDefault();
			fetchLogs();
		});

		// Handle loading spinners in product list column
		$(document).on('click', '.fps-col-loading', function (e) {
			e.preventDefault();
			var $el = $(this);
			var productId = $el.data('product-id');
			var needIds = $el.data('need') ? $el.data('need').split(',') : [];
			var divisor = parseFloat($el.data('divisor')) || 1;
			var unit = parseInt($el.data('unit'), 10) || 2;
			if (!productId || needIds.length === 0) {
				return;
			}
			var nonce = typeof FPS !== 'undefined' ? FPS.nonce : '';
			var data = new FormData();
			data.append('action', 'fps_calculate_variable');
			data.append('nonce', nonce);
			data.append('product_id', productId);
			data.append('variation_ids', needIds.join(','));
			fetch(typeof FPS !== 'undefined' ? FPS.ajaxUrl : '', { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r){ return r.json(); })
				.then(function(res){
					if (res.success && res.data && res.data.results) {
						var results = res.data.results;
						$.each(results, function(varId, varResult){
							var $loading = $('.fps-col-loading[data-product-id="' + productId + '"][data-need*="' + varId + '"]');
							if ($loading.length) {
								$loading.replaceWith('<span class="fps-col-price">' + varResult.formatted + ' <span class="' + (unit === 1 ? 'fps-unit-rial' : 'fps-unit-toman') + '">' + varResult.unit + '</span></span>');
							}
						});
					} else {
						console.error('Failed to calculate variable prices', res);
					}
				})
				.catch(function(err){
					console.error('AJAX error', err);
				});
		});
	});
})(jQuery);

	// Tax mode checkbox: primarily for currency source
	function toggleCurrencyTaxMode() {
		var source = $('#_fps_source_type').val() || 'gold_18k';
		var $mode = $('.fps-currency-tax-mode');
		if (!$mode.length) return;
		if (source === 'currency') {
			$mode.show();
		} else {
			$mode.hide();
		}
	}
	$(document).on('change', '#_fps_source_type', toggleCurrencyTaxMode);
	$(function () { toggleCurrencyTaxMode(); });

