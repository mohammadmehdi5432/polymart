/**
 * Persist PolyMart storefront language on AJAX / fetch / XHR requests.
 */
(function () {
	'use strict';

	var cfg = window.polymartAiLang || {};
	var lang = typeof cfg.lang === 'string' ? cfg.lang.trim() : '';
	var headerName = cfg.headerName || 'X-Polymart-Lang';
	var queryVar = cfg.queryVar || 'polymart_lang';
	var cookieName = cfg.cookieName || 'polymart_ai_lang';
	var fragLangKey = 'polymart_cart_frag_lang';

	function readCookie(name) {
		var pattern = '(?:^|; )' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + '=([^;]*)';
		var match = document.cookie.match(new RegExp(pattern));

		return match ? decodeURIComponent(match[1]) : '';
	}

	function clearCartFragmentCache() {
		var params = window.wc_cart_fragments_params || window.wd_cart_fragments_params;

		if (!window.sessionStorage) {
			return;
		}

		try {
			if (params) {
				sessionStorage.removeItem(params.fragment_name);

				if (params.cart_hash_key) {
					sessionStorage.removeItem(params.cart_hash_key);
				}
			}

			sessionStorage.removeItem('wc_cart_created');
			sessionStorage.removeItem(fragLangKey);
		} catch (e) {
			// Ignore storage failures.
		}
	}

	if (!lang) {
		return;
	}

	var previousLang = readCookie(cookieName);

	function appendLangQuery(url) {
		if (typeof url !== 'string' || url.indexOf(queryVar + '=') !== -1) {
			return url;
		}

		var separator = url.indexOf('?') === -1 ? '?' : '&';

		return url + separator + encodeURIComponent(queryVar) + '=' + encodeURIComponent(lang);
	}

	function setLangHeader(target) {
		if (!target || typeof target.setRequestHeader !== 'function') {
			return;
		}

		try {
			target.setRequestHeader(headerName, lang);
		} catch (e) {
			// Ignore duplicate header errors on reused XHR objects.
		}
	}

	function mergeHeaders(headers) {
		var merged = {};

		if (headers instanceof Headers) {
			headers.forEach(function (value, key) {
				merged[key] = value;
			});
		} else if (headers && typeof headers === 'object') {
			merged = Object.assign({}, headers);
		}

		if (!merged[headerName]) {
			merged[headerName] = lang;
		}

		return merged;
	}

	try {
		var maxAge = 365 * 24 * 60 * 60;
		var secure = window.location.protocol === 'https:' ? '; secure' : '';

		document.cookie = cookieName + '=' + encodeURIComponent(lang) + '; path=/; max-age=' + maxAge + '; samesite=lax' + secure;

		if (previousLang && previousLang !== lang) {
			clearCartFragmentCache();

			if (window.jQuery) {
				window.jQuery(document.body).trigger('polymart_ai_language_changed', [lang, previousLang]);
			}
		}
	} catch (e) {
		// Cookie writes may fail in sandboxed contexts.
	}

	if (window.XMLHttpRequest && XMLHttpRequest.prototype) {
		var originalOpen = XMLHttpRequest.prototype.open;
		var originalSend = XMLHttpRequest.prototype.send;

		XMLHttpRequest.prototype.open = function (method, url) {
			if (arguments.length >= 2 && typeof url === 'string') {
				arguments[1] = appendLangQuery(url);
			}

			this.__polymartAiLang = lang;
			return originalOpen.apply(this, arguments);
		};

		XMLHttpRequest.prototype.send = function () {
			if (this.__polymartAiLang) {
				setLangHeader(this);
			}

			return originalSend.apply(this, arguments);
		};
	}

	if (typeof window.fetch === 'function') {
		var originalFetch = window.fetch;

		window.fetch = function (input, init) {
			var nextInit = init ? Object.assign({}, init) : {};

			if (typeof input === 'string') {
				nextInit.headers = mergeHeaders(nextInit.headers);
				return originalFetch.call(this, appendLangQuery(input), nextInit);
			}

			if (typeof Request !== 'undefined' && input instanceof Request) {
				var requestInit = {
					method: input.method,
					headers: mergeHeaders(input.headers),
					mode: input.mode,
					credentials: input.credentials,
					cache: input.cache,
					redirect: input.redirect,
					referrer: input.referrer,
					referrerPolicy: input.referrerPolicy,
					integrity: input.integrity,
					keepalive: input.keepalive,
					signal: input.signal,
				};

				if (input.method !== 'GET' && input.method !== 'HEAD') {
					requestInit.body = input.body;
				}

				return originalFetch.call(
					this,
					new Request(appendLangQuery(input.url), requestInit),
					nextInit
				);
			}

			nextInit.headers = mergeHeaders(nextInit.headers);
			return originalFetch.call(this, input, nextInit);
		};
	}
	function patchJQuery() {
		if (!window.jQuery || typeof window.jQuery.ajaxPrefilter !== 'function') {
			return;
		}

		window.jQuery.ajaxPrefilter(function (options) {
			if (options && typeof options.url === 'string') {
				options.url = appendLangQuery(options.url);
			}

			options.headers = mergeHeaders(options.headers || {});
		});
	}

	if (window.jQuery) {
		patchJQuery();
	} else {
		document.addEventListener('DOMContentLoaded', patchJQuery);
	}
})();
