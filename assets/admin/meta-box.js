/**
 * PolyMart AI meta box — multilingual fields, AI generation, and media pickers.
 */
(function ($) {
	'use strict';

	if (typeof polymartAiMetaBox === 'undefined') {
		return;
	}

	const config = polymartAiMetaBox;
	const mediaFrames = {};

	function getLanguageConfig(lang) {
		return (config.languages || []).find(function (entry) {
			return entry.code === lang;
		});
	}

	function setLangStatus(lang, message, type) {
		const $status = $('.polymart-ai-metabox__status--lang[data-lang="' + lang + '"]');

		if (!$status.length) {
			return;
		}

		$status
			.text(message || '')
			.removeClass('is-error is-success')
			.addClass(type ? 'is-' + type : '');
	}

	function setGlobalStatus(message, type) {
		const $status = $('.polymart-ai-metabox__global-status');

		if (!$status.length) {
			return;
		}

		$status
			.text(message || '')
			.removeClass('is-error is-success')
			.addClass(type ? 'is-' + type : '');
	}

	function setFieldValue(name, value) {
		const $input = $('[name="' + name + '"]');

		if (!$input.length) {
			return;
		}

		const langEntry = (config.languages || []).find(function (entry) {
			return entry.fields && entry.fields.content === name;
		});

		if (langEntry && typeof window.tinyMCE !== 'undefined') {
			const editor = window.tinyMCE.get(langEntry.editorId);
			if (editor && !editor.isHidden()) {
				editor.setContent(value || '');
				return;
			}
		}

		$input.val(value || '');
	}

	function populateFields(fields) {
		Object.keys(fields || {}).forEach(function (key) {
			setFieldValue(key, fields[key]);
		});
	}

	function renderThumbnailPreview($field, attachment) {
		const $preview = $field.find('.polymart-ai-thumbnail-field__preview');
		const url =
			attachment?.sizes?.medium?.url ||
			attachment?.sizes?.thumbnail?.url ||
			attachment?.url ||
			'';

		if (!url) {
			$preview.html(
				'<span class="polymart-ai-thumbnail-field__placeholder">' +
					(config.strings.noImageSelected || '') +
					'</span>'
			);
			return;
		}

		$preview.html('<img src="' + url + '" alt="" />');
	}

	function setThumbnail($field, attachment) {
		if (!$field.length || !attachment) {
			return;
		}

		$field.find('.polymart-ai-thumbnail-input').val(String(attachment.id));
		renderThumbnailPreview($field, attachment);
		$field.find('.polymart-ai-thumbnail-remove').show();
	}

	function clearThumbnail($field) {
		if (!$field.length) {
			return;
		}

		$field.find('.polymart-ai-thumbnail-input').val('0');
		renderThumbnailPreview($field, null);
		$field.find('.polymart-ai-thumbnail-remove').hide();
	}

	function initThumbnailPickers() {
		if (typeof wp === 'undefined' || !wp.media) {
			return;
		}

		$('.polymart-ai-thumbnail-field').each(function () {
			const $field = $(this);
			const lang = $field.data('lang');
			const langLabel = $field.data('lang-label') || lang;
			const langConfig = getLanguageConfig(lang);

			if (langConfig?.thumbnail?.url) {
				renderThumbnailPreview($field, {
					id: langConfig.thumbnail.id,
					url: langConfig.thumbnail.url,
				});
			}

			$field.find('.polymart-ai-thumbnail-select').on('click', function (event) {
				event.preventDefault();

				if (!mediaFrames[lang]) {
					mediaFrames[lang] = wp.media({
						title:
							(config.strings.selectImage || 'Select image') +
							(langLabel ? ' — ' + langLabel : ''),
						button: {
							text: config.strings.selectImageBtn || 'Use image',
						},
						library: {
							type: 'image',
						},
						multiple: false,
					});

					mediaFrames[lang].on('select', function () {
						const attachment = mediaFrames[lang].state().get('selection').first().toJSON();
						setThumbnail($field, attachment);
					});
				}

				mediaFrames[lang].open();
			});

			$field.find('.polymart-ai-thumbnail-remove').on('click', function (event) {
				event.preventDefault();
				clearThumbnail($field);
			});
		});
	}

	function resolvePostId() {
		if (config.postId) {
			return config.postId;
		}

		const $postId = $('#post_ID');
		return $postId.length ? parseInt($postId.val(), 10) : 0;
	}

	function setGenerateLoading($btn, isLoading) {
		const langConfig = getLanguageConfig($btn.data('lang'));
		const $spinner = $btn.find('.polymart-ai-generate-btn__spinner');
		const $label = $btn.find('.polymart-ai-generate-btn__label');

		$btn.prop('disabled', isLoading);
		$spinner.css('display', isLoading ? 'inline-block' : 'none');
		$label.text(
			isLoading
				? config.strings.generating
				: langConfig?.generateLabel || $label.text()
		);
	}

	function setRetranslateLoading($btn, isLoading) {
		const $spinner = $btn.find('.polymart-ai-retranslate-all-btn__spinner');
		const $label = $btn.find('.polymart-ai-retranslate-all-btn__label');

		$btn.prop('disabled', isLoading);
		$('.polymart-ai-generate-btn').prop('disabled', isLoading);
		$spinner.css('display', isLoading ? 'inline-block' : 'none');
		$label.text(isLoading ? config.strings.retranslating : (config.strings.retranslateLabel || ''));
	}

	function initLanguageTabs() {
		$('.polymart-ai-metabox__tab').on('click', function (event) {
			event.preventDefault();

			const lang = $(this).data('lang');

			$('.polymart-ai-metabox__tab')
				.removeClass('nav-tab-active')
				.attr('aria-selected', 'false');

			$(this).addClass('nav-tab-active').attr('aria-selected', 'true');

			$('.polymart-ai-metabox__lang-panel')
				.removeClass('is-active')
				.attr('hidden', true);

			const $panel = $('.polymart-ai-metabox__lang-panel[data-lang="' + lang + '"]');
			$panel.addClass('is-active').removeAttr('hidden');
		});
	}

	function syncEditorsBeforePostSave() {
		const $form = $('#post');

		if (!$form.length || typeof window.tinyMCE === 'undefined') {
			return;
		}

		$form.on('submit', function () {
			window.tinyMCE.triggerSave();
		});
	}

	function getActiveLang() {
		const $activeTab = $('.polymart-ai-metabox__tab.nav-tab-active').first();

		if ($activeTab.length) {
			return $activeTab.data('lang');
		}

		return config.languages?.[0]?.code || '';
	}

	function updateStatusBadge(lang, status) {
		const $badge = $('.polymart-ai-metabox__tab[data-lang="' + lang + '"] .polymart-ai-metabox__status-badge');

		if (!$badge.length || !status) {
			return;
		}

		const labels = {
			translated: 'کامل',
			partial: 'ناقص',
			untranslated: 'ترجمه‌نشده',
		};

		$badge
			.removeClass('polymart-ai-metabox__status-badge--translated polymart-ai-metabox__status-badge--partial polymart-ai-metabox__status-badge--untranslated')
			.addClass('polymart-ai-metabox__status-badge--' + status)
			.text(labels[status] || status);
	}

	function renderScanResults(scan) {
		const $container = $('.polymart-ai-metabox__scan-results');

		if (!$container.length || !scan) {
			return;
		}

		const fields = scan.fields || [];
		const missing = fields.filter(function (field) {
			return !field.translated;
		});
		const done = fields.filter(function (field) {
			return field.translated;
		});
		const notes = scan.notes || [];
		let html = '';

		html += '<p class="polymart-ai-metabox__scan-summary">';
		html += '<strong>' + (config.strings.scanStatus || 'وضعیت:') + '</strong> ';
		html += (scan.lang_label || scan.lang || '') + ' — ';
		html += (scan.status_label || scan.status || '');
		html += '</p>';

		if (scan.elementor_progress) {
			html += '<p class="description">' + scan.elementor_progress + '</p>';
		}

		if (scan.elementor_error) {
			html += '<p class="polymart-ai-metabox__warning">' + scan.elementor_error + '</p>';
		}

		if (missing.length) {
			html += '<p><strong>' + (config.strings.scanMissingHeading || '') + '</strong></p>';
			html += '<ul class="polymart-ai-metabox__scan-list polymart-ai-metabox__scan-list--missing">';
			missing.forEach(function (field) {
				html += '<li>' + field.label + '</li>';
			});
			html += '</ul>';
		} else if (!fields.length) {
			html += '<p>' + (config.strings.scanEmpty || '') + '</p>';
		} else {
			html += '<p>' + (config.strings.scanComplete || '') + '</p>';
		}

		if (done.length) {
			html += '<p><strong>' + (config.strings.scanDoneHeading || '') + '</strong></p>';
			html += '<ul class="polymart-ai-metabox__scan-list polymart-ai-metabox__scan-list--done">';
			done.forEach(function (field) {
				html += '<li>' + field.label + '</li>';
			});
			html += '</ul>';
		}

		if (notes.length) {
			html += '<ul class="polymart-ai-metabox__scan-notes">';
			notes.forEach(function (note) {
				html += '<li>' + note + '</li>';
			});
			html += '</ul>';
		}

		html += '<p class="description">' + (config.strings.activeLangHint || '') + '</p>';

		$container.html(html).removeAttr('hidden');
	}

	function setWorkflowLoading($btn, isLoading, loadingText, defaultText) {
		const $spinner = $btn.find('.spinner');
		const $label = $btn.find('span').not('.spinner').first();

		$btn.prop('disabled', isLoading);
		$('.polymart-ai-scan-gaps-btn, .polymart-ai-translate-complete-btn, .polymart-ai-generate-btn, .polymart-ai-retranslate-all-btn')
			.not($btn)
			.prop('disabled', isLoading);
		$spinner.css('display', isLoading ? 'inline-block' : 'none');

		if ($label.length) {
			$label.text(isLoading ? loadingText : defaultText);
		}
	}

	function shouldUseChunkedTranslation() {
		return config.postType === 'page' && !!config.usesElementor;
	}

	function requestTranslateComplete(postId, lang, options) {
		options = options || {};

		return $.ajax({
			url: config.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'polymart_translate_post_complete',
				nonce: config.nonce,
				post_id: postId,
				lang: lang,
				force: options.force ? 1 : 0,
			},
		});
	}

	function pollTranslateComplete(postId, lang, attempt) {
		attempt = attempt || 0;

		if (attempt > 120) {
			setGlobalStatus(config.strings.error, 'error');
			setWorkflowLoading($('.polymart-ai-translate-complete-btn'), false, '', config.strings.translateCompleteLabel || '');
			return;
		}

		requestTranslateComplete(postId, lang, { force: false })
			.done(function (response) {
				if (!response || !response.success) {
					const message =
						response && response.data && response.data.message
							? response.data.message
							: config.strings.error;
					setGlobalStatus(message, 'error');
					setLangStatus(lang, message, 'error');
					setWorkflowLoading($('.polymart-ai-translate-complete-btn'), false, '', config.strings.translateCompleteLabel || '');
					return;
				}

				const data = response.data || {};

				if (data.fields) {
					populateFields(data.fields);
				}

				if (data.scan) {
					renderScanResults(data.scan);
					updateStatusBadge(lang, data.scan.status);
				}

				if (data.done) {
					setGlobalStatus(data.message || config.strings.translateCompleteSuccess, 'success');
					setLangStatus(lang, data.message || config.strings.translateCompleteSuccess, 'success');
					setWorkflowLoading($('.polymart-ai-translate-complete-btn'), false, '', config.strings.translateCompleteLabel || '');
					return;
				}

				const progress = data.phase_progress ? ' (' + data.phase_progress + ')' : '';
				setGlobalStatus((data.message || config.strings.translating) + progress, '');
				setLangStatus(lang, (data.message || config.strings.translating) + progress, '');

				window.setTimeout(function () {
					pollTranslateComplete(postId, lang, attempt + 1);
				}, 2000);
			})
			.fail(function (xhr) {
				let message = config.strings.error;

				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}

				setGlobalStatus(message, 'error');
				setLangStatus(lang, message, 'error');
				setWorkflowLoading($('.polymart-ai-translate-complete-btn'), false, '', config.strings.translateCompleteLabel || '');
			});
	}

	function initScanGapsButton() {
		$('.polymart-ai-scan-gaps-btn').on('click', function (event) {
			event.preventDefault();

			const $btn = $(this);
			const postId = resolvePostId();
			const lang = getActiveLang();

			if (!postId) {
				setGlobalStatus(config.strings.noPostId, 'error');
				return;
			}

			if (!lang) {
				setGlobalStatus(config.strings.error, 'error');
				return;
			}

			setWorkflowLoading($btn, true, config.strings.scanning, config.strings.scanLabel || '');
			setGlobalStatus('', '');

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'polymart_scan_translation_gaps',
					nonce: config.nonce,
					post_id: postId,
					lang: lang,
				},
			})
				.done(function (response) {
					if (!response || !response.success) {
						const message =
							response && response.data && response.data.message
								? response.data.message
								: config.strings.error;
						setGlobalStatus(message, 'error');
						return;
					}

					renderScanResults(response.data);
					updateStatusBadge(lang, response.data.status);
					setGlobalStatus(
						(config.strings.scanStatus || '') + ' ' + (response.data.status_label || ''),
						response.data.needs_work ? '' : 'success'
					);
				})
				.fail(function (xhr) {
					let message = config.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}

					setGlobalStatus(message, 'error');
				})
				.always(function () {
					setWorkflowLoading($btn, false, '', config.strings.scanLabel || '');
				});
		});
	}

	function initTranslateCompleteButton() {
		$('.polymart-ai-translate-complete-btn').on('click', function (event) {
			event.preventDefault();

			const $btn = $(this);
			const postId = resolvePostId();
			const lang = getActiveLang();
			const force = event.shiftKey;

			if (!postId) {
				setGlobalStatus(config.strings.noPostId, 'error');
				return;
			}

			if (!lang) {
				setGlobalStatus(config.strings.error, 'error');
				return;
			}

			if (force && !window.confirm(config.strings.confirmForceTranslate || 'Continue?')) {
				return;
			}

			setWorkflowLoading($btn, true, config.strings.translating, config.strings.translateCompleteLabel || '');
			setGlobalStatus('', '');
			setLangStatus(lang, '', '');

			requestTranslateComplete(postId, lang, { force: force })
				.done(function (response) {
					if (!response || !response.success) {
						const message =
							response && response.data && response.data.message
								? response.data.message
								: config.strings.error;
						setGlobalStatus(message, 'error');
						setLangStatus(lang, message, 'error');
						setWorkflowLoading($btn, false, '', config.strings.translateCompleteLabel || '');
						return;
					}

					const data = response.data || {};

					if (data.fields) {
						populateFields(data.fields);
					}

					if (data.scan) {
						renderScanResults(data.scan);
						updateStatusBadge(lang, data.scan.status);
					}

					if (data.done) {
						setGlobalStatus(data.message || config.strings.translateCompleteSuccess, 'success');
						setLangStatus(lang, data.message || config.strings.translateCompleteSuccess, 'success');
						setWorkflowLoading($btn, false, '', config.strings.translateCompleteLabel || '');
						return;
					}

					pollTranslateComplete(postId, lang, 0);
				})
				.fail(function (xhr) {
					let message = config.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}

					setGlobalStatus(message, 'error');
					setLangStatus(lang, message, 'error');
					setWorkflowLoading($btn, false, '', config.strings.translateCompleteLabel || '');
				});
		});
	}

	function initGenerateButtons() {
		$('.polymart-ai-generate-btn[data-lang]').on('click', function (event) {
			event.preventDefault();

			const $btn = $(this);
			const lang = $btn.data('lang');
			const postId = resolvePostId();

			if (!postId) {
				setLangStatus(lang, config.strings.noPostId, 'error');
				return;
			}

			setGenerateLoading($btn, true);
			setLangStatus(lang, '', '');

			if (shouldUseChunkedTranslation()) {
				requestTranslateComplete(postId, lang, { force: false })
					.done(function (response) {
						if (!response || !response.success) {
							const message =
								response && response.data && response.data.message
									? response.data.message
									: config.strings.error;
							setLangStatus(lang, message, 'error');
							setGenerateLoading($btn, false);
							return;
						}

						const data = response.data || {};

						if (data.fields) {
							populateFields(data.fields);
						}

						if (data.scan) {
							renderScanResults(data.scan);
							updateStatusBadge(lang, data.scan.status);
						}

						if (!data.done) {
							setLangStatus(lang, data.message || config.strings.translating, '');
							pollTranslateComplete(postId, lang, 0);
							setGenerateLoading($btn, false);
							return;
						}

						setLangStatus(lang, data.message || config.strings.success, 'success');
						setGenerateLoading($btn, false);
					})
					.fail(function (xhr) {
						let message = config.strings.error;

						if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
							message = xhr.responseJSON.data.message;
						}

						setLangStatus(lang, message, 'error');
						setGenerateLoading($btn, false);
					});

				return;
			}

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'polymart_generate_translation',
					nonce: config.nonce,
					post_id: postId,
					lang: lang,
				},
			})
				.done(function (response) {
					if (!response || !response.success) {
						const message =
							response && response.data && response.data.message
								? response.data.message
								: config.strings.error;
						setLangStatus(lang, message, 'error');
						return;
					}

					if (response.data && response.data.fields) {
						populateFields(response.data.fields);
					}

					setLangStatus(lang, response.data.message || config.strings.success, 'success');
				})
				.fail(function (xhr) {
					let message = config.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}

					setLangStatus(lang, message, 'error');
				})
				.always(function () {
					setGenerateLoading($btn, false);
				});
		});
	}

	function initRetranslateAllButton() {
		$('.polymart-ai-retranslate-all-btn').on('click', function (event) {
			event.preventDefault();

			if (!window.confirm(config.strings.confirmRetranslate || 'Continue?')) {
				return;
			}

			const $btn = $(this);
			const postId = resolvePostId();

			if (!postId) {
				setGlobalStatus(config.strings.noPostId, 'error');
				return;
			}

			setRetranslateLoading($btn, true);
			setGlobalStatus('', '');

			(config.languages || []).forEach(function (entry) {
				setLangStatus(entry.code, '', '');
			});

			$.ajax({
				url: config.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'polymart_retranslate_product',
					nonce: config.nonce,
					post_id: postId,
				},
			})
				.done(function (response) {
					if (!response || !response.success) {
						const message =
							response && response.data && response.data.message
								? response.data.message
								: config.strings.error;
						setGlobalStatus(message, 'error');
						return;
					}

					if (response.data && response.data.fields) {
						populateFields(response.data.fields);
					}

					setGlobalStatus(
						response.data.message || config.strings.retranslateSuccess,
						'success'
					);
				})
				.fail(function (xhr) {
					let message = config.strings.error;

					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}

					setGlobalStatus(message, 'error');
				})
				.always(function () {
					setRetranslateLoading($btn, false);
				});
		});
	}

	initLanguageTabs();
	initThumbnailPickers();
	syncEditorsBeforePostSave();
	initScanGapsButton();
	initTranslateCompleteButton();
	initGenerateButtons();
	initRetranslateAllButton();
})(jQuery);
