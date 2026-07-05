/**
 * WoodMart / WooCommerce mini-cart compatibility for PolyMart multilingual storefronts.
 */
(function ($) {
	'use strict';

	var cfg = window.polymartAiCartCompat || {};
	var MINI_CART_SELECTOR = '.cart-widget-side .widget_shopping_cart_content,.woocommerce.widget_shopping_cart .widget_shopping_cart_content';
	var SIDE_CART_ITEM_SELECTOR = '.cart-widget-side .mini_cart_item';
	var FRAG_LANG_KEY = 'polymart_cart_frag_lang';

	var refreshPending = false;
	var refreshWaiters = [];
	var hydrated = false;

	function getHeaderCartCount() {
		var count = 0;
		var $badge = $('.wd-cart-number').first();

		if ($badge.length) {
			var parsed = parseInt(String($badge.text()).replace(/[^\d]/g, ''), 10);

			if (!isNaN(parsed)) {
				count = parsed;
			}
		}

		if (count > 0) {
			return count;
		}

		if (typeof Cookies !== 'undefined') {
			return parseInt(Cookies.get('woocommerce_items_in_cart') || 0, 10);
		}

		var match = document.cookie.match(/(?:^|; )woocommerce_items_in_cart=(\d+)/);

		return match ? parseInt(match[1], 10) : 0;
	}

	function getSideCartItemCount() {
		return $(SIDE_CART_ITEM_SELECTOR).length;
	}

	function getMiniCartDomHtml() {
		var html = '';

		$(MINI_CART_SELECTOR).each(function () {
			html = $(this).html();

			if ($.trim(html) !== '') {
				return false;
			}
		});

		return html;
	}

	function miniCartHtmlLooksEmpty(expectedCount) {
		var html = getMiniCartDomHtml();

		if ($.trim(html) === '') {
			return true;
		}

		if (html.indexOf('wd-empty-mini-cart') !== -1 || html.indexOf('woocommerce-mini-cart__empty-message') !== -1) {
			return true;
		}

		if (expectedCount > 0 && html.indexOf('mini_cart_item') === -1) {
			return true;
		}

		return false;
	}

	function sideCartNeedsHydration() {
		if (hydrated) {
			return false;
		}

		var expectedCount = getHeaderCartCount();

		if (expectedCount <= 0) {
			return false;
		}

		if (getSideCartItemCount() > 0) {
			hydrated = true;
			return false;
		}

		return miniCartHtmlLooksEmpty(expectedCount);
	}

	function getFragmentParams() {
		return window.wc_cart_fragments_params || window.wd_cart_fragments_params || null;
	}

	function getPageLang() {
		var langCfg = window.polymartAiLang || {};
		return typeof langCfg.lang === 'string' ? langCfg.lang.trim() : '';
	}

	function clearStoredFragments() {
		var params = getFragmentParams();

		if (!params || !window.sessionStorage) {
			return;
		}

		try {
			sessionStorage.removeItem(params.fragment_name);

			if (params.cart_hash_key) {
				sessionStorage.removeItem(params.cart_hash_key);
			}

			sessionStorage.removeItem('wc_cart_created');
			sessionStorage.removeItem(FRAG_LANG_KEY);
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function persistFragments(fragments, cartHash) {
		var params = getFragmentParams();

		if (!params || !window.sessionStorage || !fragments) {
			return;
		}

		try {
			sessionStorage.setItem(params.fragment_name, JSON.stringify(fragments));

			if (cartHash && params.cart_hash_key) {
				sessionStorage.setItem(params.cart_hash_key, cartHash);
				sessionStorage.setItem('wc_cart_created', String(new Date().getTime()));
			}

			var pageLang = getPageLang();

			if (pageLang) {
				sessionStorage.setItem(FRAG_LANG_KEY, pageLang);
			}
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function setSideCartLoading(isLoading) {
		$('.cart-widget-side').toggleClass('polymart-ai-cart-loading', !!isLoading);
	}

	function applyCartFragments(fragments, cartHash) {
		if (!fragments) {
			return;
		}

		$.each(fragments, function (key, value) {
			$(String(key).replace('_wd', '')).replaceWith(value);
		});

		persistFragments(fragments, cartHash);
		hydrated = getSideCartItemCount() > 0 || !miniCartHtmlLooksEmpty(getHeaderCartCount());
		setSideCartLoading(false);
		$(document.body).trigger('wc_fragments_refreshed');
	}

	function flushRefreshWaiters(success) {
		var waiters = refreshWaiters.slice(0);
		refreshWaiters = [];
		refreshPending = false;

		waiters.forEach(function (callback) {
			callback(success);
		});
	}

	function fetchMiniCartFragments(callback) {
		if (typeof callback === 'function') {
			refreshWaiters.push(callback);
		}

		if (refreshPending) {
			return;
		}

		if (!cfg.ajaxUrl || !cfg.action) {
			flushRefreshWaiters(false);
			return;
		}

		refreshPending = true;
		setSideCartLoading(true);

		$.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			data: {
				action: cfg.action,
				_ajax_nonce: cfg.nonce || '',
			},
			dataType: 'json',
		})
			.done(function (response) {
				if (response && response.success && response.data && response.data.fragments) {
					applyCartFragments(response.data.fragments, response.data.cart_hash);
					flushRefreshWaiters(true);
					return;
				}

				setSideCartLoading(false);
				flushRefreshWaiters(false);
			})
			.fail(function () {
				setSideCartLoading(false);
				flushRefreshWaiters(false);
			});
	}

	function clearStaleSessionFragments() {
		try {
			var params = getFragmentParams();
			var match = document.cookie.match(/(?:^|; )woocommerce_items_in_cart=(\d+)/);
			var count = match ? parseInt(match[1], 10) : 0;
			var pageLang = getPageLang();
			var storedLang = window.sessionStorage ? sessionStorage.getItem(FRAG_LANG_KEY) : null;

			if (storedLang && pageLang && storedLang !== pageLang) {
				clearStoredFragments();
				return;
			}

			if (!params || !window.sessionStorage || count <= 0) {
				return;
			}

			var raw = sessionStorage.getItem(params.fragment_name);

			if (!raw) {
				return;
			}

			var frags = JSON.parse(raw);
			var html = frags && frags['div.widget_shopping_cart_content'];

			if (typeof html !== 'string' || (count > 0 && html.indexOf('mini_cart_item') === -1)) {
				sessionStorage.removeItem(params.fragment_name);

				if (params.cart_hash_key) {
					sessionStorage.removeItem(params.cart_hash_key);
				}
			}
		} catch (e) {
			// Ignore malformed sessionStorage payloads.
		}
	}

	function openSideCartDirectly() {
		if ($('body').hasClass('woocommerce-cart') || $('body').hasClass('woocommerce-checkout')) {
			return;
		}

		var $side = $('.cart-widget-side');

		if (!$side.length) {
			return;
		}

		$side.addClass('wd-opened');
		$('.wd-close-side').addClass('wd-close-side-opened');
	}

	function openWoodmartSideCartAfterAdd() {
		if (typeof woodmart_settings === 'undefined' || woodmart_settings.add_to_cart_action !== 'widget') {
			return;
		}

		window.setTimeout(openSideCartDirectly, 60);
	}

	function prefetchSideCart() {
		if (!sideCartNeedsHydration()) {
			return;
		}

		fetchMiniCartFragments();
	}

	function bindCartInteractions() {
		$(document.body).on('mouseenter touchstart', '.cart-widget-opener', function () {
			prefetchSideCart();
		});

		document.addEventListener(
			'click',
			function (event) {
				if (!event.target || !event.target.closest) {
					return;
				}

				var opener = event.target.closest('.cart-widget-opener');

				if (!opener) {
					return;
				}

				if ($('.cart-widget-side').hasClass('wd-opened')) {
					return;
				}

				if (!sideCartNeedsHydration()) {
					return;
				}

				event.preventDefault();
				event.stopImmediatePropagation();

				openSideCartDirectly();
				prefetchSideCart();
			},
			true
		);

		$(document.body).on('added_to_cart', function (event, fragments) {
			if (fragments && (fragments.stop_reload || fragments.e_manually_triggered)) {
				return;
			}

			hydrated = true;
			openWoodmartSideCartAfterAdd();
		});

		$(document.body).on('removed_from_cart', function () {
			hydrated = false;
		});
	}

	clearStaleSessionFragments();

	$(function () {
		var pageLang = getPageLang();
		var storedLang = window.sessionStorage ? sessionStorage.getItem(FRAG_LANG_KEY) : null;

		if (storedLang && pageLang && storedLang !== pageLang) {
			clearStoredFragments();
			hydrated = false;
			fetchMiniCartFragments();
		}

		prefetchSideCart();
		bindCartInteractions();

		$(document.body).on('polymart_ai_language_changed', function () {
			clearStoredFragments();
			hydrated = false;
			fetchMiniCartFragments();
		});

		$(document.body).on('wc_fragments_loaded wc_fragments_refreshed', function () {
			window.setTimeout(function () {
				if (getSideCartItemCount() > 0) {
					hydrated = true;
					return;
				}

				if (sideCartNeedsHydration()) {
					hydrated = false;
					prefetchSideCart();
				}
			}, 0);
		});
	});
})(jQuery);
