/* Milieus by Therum — admin role builder + default-role auto-save */
(function() {
	'use strict';

	var ROLES = window.MilieusRoles || {};
	var AJAX  = window.MilieusAjax || (window.ajaxurl || '/wp-admin/admin-ajax.php');

	// ── Role builder ──────────────────────────────────────────────────────
	var builder = document.querySelector('[data-milieus-role-builder]');
	if (builder) {
		var nonce    = builder.getAttribute('data-nonce');
		var editor   = builder.querySelector('[data-role-editor]');
		var resultEl = builder.querySelector('[data-role-result]');

		function $(s, ctx)  { return (ctx || builder).querySelector(s); }
		function $$(s, ctx) { return Array.prototype.slice.call((ctx || builder).querySelectorAll(s)); }
		function setVal(sel, v) { var el = $(sel); if (el) el.value = v; }
		function getVal(sel)    { var el = $(sel); return el ? el.value : ''; }

		function openEditor(role) {
			editor.hidden = false;
			resultEl.textContent = '';
			var isNew = !role;
			$('[data-role-editor-title]').textContent = isNew ? 'New custom role' : 'Edit role: ' + role.name;
			setVal('[data-role-key]', role ? role.key : '');
			setVal('[data-role-is-new]', isNew ? '1' : '0');
			setVal('[data-role-name]', role ? role.name : '');
			setVal('[data-role-discount]', role ? role.discount : 0);
			$$('[data-bundle]').forEach(function(cb) {
				cb.checked = role && role.bundles && role.bundles.indexOf(cb.value) >= 0;
				var card = cb.closest('[data-bundle-card]');
				if (card) card.classList.toggle('active', cb.checked);
			});
			$$('[data-cap]').forEach(function(cb) {
				cb.checked = role && role.caps && role.caps.indexOf(cb.value) >= 0;
				var pill = cb.closest('[data-cap-pill]');
				if (pill) pill.classList.toggle('active', cb.checked);
			});
			var delBtn = $('[data-role-delete]');
			if (delBtn) delBtn.hidden = isNew;
			editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		function closeEditor() {
			editor.hidden = true;
			resultEl.textContent = '';
		}

		$('[data-role-new]').addEventListener('click', function() { openEditor(null); });

		// Delegated edit click — anywhere on the page (roles overview table is outside the builder).
		document.addEventListener('click', function(e) {
			var editBtn = e.target.closest('[data-role-edit]');
			if (editBtn) {
				e.preventDefault();
				var k = editBtn.getAttribute('data-role-edit');
				if (ROLES[k]) openEditor(ROLES[k]);
			}
		});

		$$('[data-role-cancel]').forEach(function(b) { b.addEventListener('click', closeEditor); });

		builder.addEventListener('change', function(e) {
			var t = e.target;
			if (t.matches && t.matches('[data-bundle]')) {
				var card = t.closest('[data-bundle-card]');
				if (card) card.classList.toggle('active', t.checked);
			}
			if (t.matches && t.matches('[data-cap]')) {
				var pill = t.closest('[data-cap-pill]');
				if (pill) pill.classList.toggle('active', t.checked);
			}
		});

		var capSearch = $('[data-cap-search]');
		if (capSearch) {
			capSearch.addEventListener('input', function() {
				var q = capSearch.value.toLowerCase().trim();
				$$('[data-cap-pill]').forEach(function(p) {
					var name = p.getAttribute('data-cap-name') || '';
					p.style.display = (!q || name.indexOf(q) >= 0) ? '' : 'none';
				});
			});
		}

		$('[data-role-save]').addEventListener('click', function() {
			var btn = this;
			var bundles = $$('[data-bundle]:checked').map(function(c) { return c.value; });
			var caps    = $$('[data-cap]:checked').map(function(c) { return c.value; });
			var fd = new FormData();
			fd.append('action', 'milieus_role_save');
			fd.append('nonce', nonce);
			fd.append('key',      getVal('[data-role-key]'));
			fd.append('name',     getVal('[data-role-name]'));
			fd.append('is_new',   getVal('[data-role-is-new]'));
			fd.append('discount', getVal('[data-role-discount]'));
			bundles.forEach(function(b) { fd.append('bundles[]', b); });
			caps.forEach(function(c) { fd.append('caps[]', c); });

			btn.disabled = true; btn.style.opacity = '0.6';
			resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx2,#666)';

			fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					btn.disabled = false; btn.style.opacity = '';
					if (j && j.success) {
						resultEl.textContent = '✓ ' + (j.data.msg || 'Saved');
						resultEl.style.color = 'var(--ok,#10b981)';
						setTimeout(function() { location.reload(); }, 700);
					} else {
						resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
						resultEl.style.color = 'var(--err,#ef4444)';
					}
				})
				.catch(function() {
					btn.disabled = false; btn.style.opacity = '';
					resultEl.textContent = '✗ Network error';
					resultEl.style.color = 'var(--err,#ef4444)';
				});
		});

		$('[data-role-delete]').addEventListener('click', function() {
			var key  = getVal('[data-role-key]');
			var name = getVal('[data-role-name]');
			if (!key) return;
			if (!confirm('Delete role "' + name + '"? Users will be reassigned to the default role.')) return;

			var fd = new FormData();
			fd.append('action', 'milieus_role_delete');
			fd.append('nonce', nonce);
			fd.append('key', key);

			resultEl.textContent = 'Deleting…';
			fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j && j.success) {
						resultEl.textContent = '✓ ' + (j.data.msg || 'Deleted');
						resultEl.style.color = 'var(--ok,#10b981)';
						setTimeout(function() { location.reload(); }, 800);
					} else {
						resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
						resultEl.style.color = 'var(--err,#ef4444)';
					}
				});
		});
	}

	// ── Auto-saving select (default role) ────────────────────────────────
	document.addEventListener('change', function(e) {
		var sel = e.target.closest('[data-milieus-select]');
		if (!sel) return;

		var name  = sel.getAttribute('data-milieus-select');
		var nonce = sel.getAttribute('data-nonce');
		var resultEl = sel.parentElement && sel.parentElement.querySelector('[data-milieus-select-result]');

		// Only `default_role` for now — generic action name keeps it simple.
		var action = name === 'default_role' ? 'milieus_default_role_save' : null;
		if (!action) return;

		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', nonce);
		fd.append('value', sel.value);

		if (resultEl) { resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx3,#999)'; }

		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (!resultEl) return;
				if (j && j.success) {
					resultEl.textContent = '✓ Saved';
					resultEl.style.color = 'var(--ok,#10b981)';
					setTimeout(function() { resultEl.textContent = ''; }, 1500);
				} else {
					resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
					resultEl.style.color = 'var(--err,#ef4444)';
				}
			})
			.catch(function() {
				if (resultEl) { resultEl.textContent = '✗ Network error'; resultEl.style.color = 'var(--err,#ef4444)'; }
			});
	});
})();
