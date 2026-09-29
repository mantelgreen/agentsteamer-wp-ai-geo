(function () {
	'use strict';

	var cfg = window.AgentSteamerAI || {};
	var i18n = cfg.i18n || {};

	function api(path, data) {
		var url = String(cfg.restUrl || '').replace(/\/$/, '') + path;
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify(data || {})
		}).then(function (res) {
			return res.json().then(function (body) {
				if (!res.ok) {
					var msg = body && body.message ? body.message : (i18n.error || 'error') + res.status;
					throw new Error(msg);
				}
				return body;
			});
		});
	}

	function $(id) {
		return document.getElementById(id);
	}

	function setStatus(el, text, isError) {
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.className = 'asi-inline-status' + (isError ? ' asi-error' : ' asi-ok');
	}

	function toMessage(err) {
		var m = (err && err.message) || (i18n.error || 'error');
		if (err && (err.code === 'invalid_json' || /JSON/i.test(m))) {
			return '请求超时或返回异常（通常是模型响应过慢或服务器网关超时），请稍后重试或改用更快的模型。';
		}
		return m;
	}

	/* ---------------- Article generator (streaming) ---------------- */

	var streamBody = '';
	var lastMeta = null;
	var controller = null;

	function collectForm() {
		return {
			topic: $('asi-topic') ? $('asi-topic').value : '',
			prompt: $('asi-prompt') ? $('asi-prompt').value : '',
			keywords: $('asi-keywords') ? $('asi-keywords').value : '',
			audience: $('asi-audience') ? $('asi-audience').value : '',
			tone: $('asi-tone') ? $('asi-tone').value : '',
			length: $('asi-length') ? parseInt($('asi-length').value, 10) || 1200 : 1200,
			language: $('asi-language') ? $('asi-language').value : 'zh-CN',
			post_type: $('asi-post-type') ? $('asi-post-type').value : 'post'
		};
	}

	function safeHtml(html) {
		return String(html || '')
			.replace(/<\s*(script|style|iframe|object|embed)[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
			.replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
			.replace(/javascript:/gi, '');
	}

	function setStreamStatus(text) {
		var el = $('asi-stream-status');
		if (el) { el.textContent = text || ''; }
	}

	function resetOutput() {
		streamBody = '';
		lastMeta = null;
		var win = $('asi-stream');
		if (win) {
			win.innerHTML = '<p class="asi-stream-empty" id="asi-stream-empty">' + (i18n.streamEmpty || '') + '</p>';
		}
		if ($('asi-result')) { $('asi-result').classList.add('asi-hidden'); }
	}

	function fillMeta(m) {
		lastMeta = m || {};
		if ($('asi-result-title')) { $('asi-result-title').value = lastMeta.title || ''; }
		if ($('asi-result-excerpt')) { $('asi-result-excerpt').value = lastMeta.excerpt || ''; }
		if ($('asi-result-metatitle')) { $('asi-result-metatitle').value = lastMeta.meta_title || ''; }
		if ($('asi-result-metadesc')) { $('asi-result-metadesc').value = lastMeta.meta_description || ''; }
		if ($('asi-result-keyword')) { $('asi-result-keyword').value = lastMeta.focus_keyword || ''; }
		if ($('asi-result-tags')) { $('asi-result-tags').value = (lastMeta.tags || []).join(', '); }
	}

	function renderStream() {
		var win = $('asi-stream');
		if (!win) { return; }
		var empty = $('asi-stream-empty');
		if (empty) { empty.parentNode.removeChild(empty); }
		win.innerHTML = safeHtml(streamBody);
		win.scrollTop = win.scrollHeight;
	}

	function enableIdle() {
		if ($('asi-generate')) { $('asi-generate').disabled = false; }
		if ($('asi-stop')) { $('asi-stop').disabled = true; }
		controller = null;
	}

	function finishStream() {
		if ($('asi-result')) { $('asi-result').classList.remove('asi-hidden'); }
		var note = i18n.streamDone || '';
		if (lastMeta && ((lastMeta.faq && lastMeta.faq.length) || (lastMeta.howto && lastMeta.howto.steps && lastMeta.howto.steps.length))) {
			note += ' ' + (i18n.schemaAdded || '');
		}
		setStreamStatus(note);
		enableIdle();
	}

	function handleFrame(frame) {
		var ev = '';
		var data = '';
		frame.split('\n').forEach(function (line) {
			if (line.indexOf('event:') === 0) { ev = line.slice(6).trim(); }
			else if (line.indexOf('data:') === 0) { data += line.slice(5).trim(); }
		});
		if (!ev) { return; }
		var payload = {};
		try { payload = JSON.parse(data || '{}'); } catch (e) { payload = {}; }

		if (ev === 'status') { setStreamStatus(payload.text || ''); }
		else if (ev === 'delta') { streamBody += (payload.t || ''); renderStream(); }
		else if (ev === 'meta') { fillMeta(payload); }
		else if (ev === 'error') { setStreamStatus(payload.message || (i18n.error || '')); }
	}

	function startStream() {
		var form = $('asi-generator-form');
		if (!form) { return; }
		var data = collectForm();
		if (!data.topic && !data.prompt) { setStreamStatus('请填写文章主题。'); return; }

		resetOutput();
		setStreamStatus(i18n.generating || '...');
		if ($('asi-generate')) { $('asi-generate').disabled = true; }
		if ($('asi-stop')) { $('asi-stop').disabled = false; }

		controller = new AbortController();
		var fd = new FormData(form);

		fetch(cfg.adminPost, { method: 'POST', body: fd, credentials: 'same-origin', signal: controller.signal })
			.then(function (res) {
				if (!res.ok || !res.body) { throw new Error('HTTP ' + res.status); }
				var reader = res.body.getReader();
				var decoder = new TextDecoder();
				var buffer = '';

				function pump() {
					return reader.read().then(function (chunk) {
						if (chunk.done) { finishStream(); return; }
						buffer += decoder.decode(chunk.value, { stream: true });
						var idx;
						while ((idx = buffer.indexOf('\n\n')) !== -1) {
							var frame = buffer.slice(0, idx);
							buffer = buffer.slice(idx + 2);
							handleFrame(frame);
						}
						return pump();
					});
				}
				return pump();
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') { setStreamStatus(i18n.stopped || 'stopped'); }
				else { setStreamStatus(toMessage(err)); }
				enableIdle();
			});
	}

	var genForm = $('asi-generator-form');
	if (genForm) {
		genForm.addEventListener('submit', function (e) {
			e.preventDefault();
			startStream();
		});
	}

	var stopBtn = $('asi-stop');
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			if (controller) { controller.abort(); }
		});
	}

	var regen = $('asi-regenerate');
	if (regen) {
		regen.addEventListener('click', function () { startStream(); });
	}

	var saveBtn = $('asi-save-draft');
	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			if (!streamBody) { return; }
			var status = $('asi-save-status');
			var m = lastMeta || {};
			setStatus(status, i18n.saving || '...');
			saveBtn.disabled = true;

			var payload = {
				title: $('asi-result-title') ? $('asi-result-title').value : (m.title || ''),
				excerpt: $('asi-result-excerpt') ? $('asi-result-excerpt').value : (m.excerpt || ''),
				meta_title: $('asi-result-metatitle') ? $('asi-result-metatitle').value : (m.meta_title || ''),
				meta_description: $('asi-result-metadesc') ? $('asi-result-metadesc').value : (m.meta_description || ''),
				focus_keyword: $('asi-result-keyword') ? $('asi-result-keyword').value : (m.focus_keyword || ''),
				tags: ($('asi-result-tags') ? $('asi-result-tags').value : '').split(',').map(function (t) { return t.trim(); }).filter(Boolean),
				faq: (m.faq || []),
				howto: (m.howto || {}),
				content_html: streamBody,
				post_type: $('asi-post-type') ? $('asi-post-type').value : 'post',
				status: 'draft'
			};

			api('/articles', payload)
				.then(function (res) {
					setStatus(status, i18n.saved || 'saved');
					if (res && res.edit_url) {
						status.innerHTML = (i18n.saved || 'saved') + ' <a href="' + res.edit_url + '">编辑</a>';
					}
				})
				.catch(function (err) {
					setStatus(status, toMessage(err), true);
				})
				.finally(function () {
					saveBtn.disabled = false;
				});
		});
	}

	/* ---------------- Metabox meta generator ---------------- */

	var metaBtn = $('asi-generate-meta');
	if (metaBtn) {
		metaBtn.addEventListener('click', function () {
			var status = $('asi-meta-status');
			setStatus(status, i18n.generating || '...');
			metaBtn.disabled = true;
			api('/generate/meta', { post_id: parseInt(metaBtn.getAttribute('data-post'), 10) })
				.then(function (res) {
					if ($('asi_title')) { $('asi_title').value = res.title || ''; }
					if ($('asi_description')) { $('asi_description').value = res.description || ''; }
					if ($('asi_focus_keyword')) { $('asi_focus_keyword').value = res.keyword || ''; }
					setStatus(status, i18n.saved || 'ok');
				})
				.catch(function (err) {
					setStatus(status, toMessage(err), true);
				})
				.finally(function () {
					metaBtn.disabled = false;
				});
		});
	}

	/* ---------------- Metabox schema extract ---------------- */

	var extractBtn = $('asi-extract-schema');
	if (extractBtn) {
		extractBtn.addEventListener('click', function () {
			var status = $('asi-schema-status');
			setStatus(status, i18n.generating || '...');
			extractBtn.disabled = true;
			api('/schema/extract', { post_id: parseInt(extractBtn.getAttribute('data-post'), 10) })
				.then(function (res) {
					if (res.faq && res.faq.length && $('asi_schema_faq')) {
						$('asi_schema_faq').value = res.faq.map(function (item) {
							return item.q + ' || ' + item.a;
						}).join('\n');
					}
					if (res.howto && res.howto.steps && res.howto.steps.length) {
						if ($('asi_schema_howto')) {
							$('asi_schema_howto').value = res.howto.steps.map(function (s) {
								return (s.name || '') + ' || ' + (s.text || '');
							}).join('\n');
						}
						if (res.howto.name && $('asi_schema_howto_name')) {
							$('asi_schema_howto_name').value = res.howto.name;
						}
					}
					setStatus(status, i18n.saved || 'ok');
				})
				.catch(function (err) {
					setStatus(status, toMessage(err), true);
				})
				.finally(function () {
					extractBtn.disabled = false;
				});
		});
	}

	/* ---------------- Settings: tabs, presets, test ---------------- */
	var tabs = document.querySelectorAll('.asi-tabs .nav-tab');
	if (tabs.length) {
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				var target = tab.getAttribute('data-tab');
				document.querySelectorAll('.asi-tabs .nav-tab').forEach(function (t) { t.classList.remove('nav-tab-active'); });
				tab.classList.add('nav-tab-active');
				document.querySelectorAll('.asi-panel').forEach(function (p) {
					p.classList.toggle('asi-hidden', p.getAttribute('data-panel') !== target);
				});
			});
		});
	}

	var providerSelect = $('asi-provider');
	var baseUrl = $('asi-base-url');
	var modelField = $('asi-model');
	if (providerSelect && cfg.presets) {
		providerSelect.addEventListener('change', function () {
			var preset = cfg.presets[providerSelect.value];
			if (!preset) {
				return;
			}
			if (baseUrl && preset.base_url) { baseUrl.value = preset.base_url; }
			if (modelField && preset.model) { modelField.value = preset.model; }
		});
	}

	var testBtn = $('asi-test-provider');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var status = $('asi-test-status');
			setStatus(status, i18n.testing || '...');
			testBtn.disabled = true;
			api('/providers/test', {})
				.then(function (res) {
					setStatus(status, 'OK · ' + (res.elapsed || '?') + 's · ' + (res.message || ''));
				})
				.catch(function (err) {
					setStatus(status, toMessage(err), true);
				})
				.finally(function () {
					testBtn.disabled = false;
				});
		});
	}
})();
