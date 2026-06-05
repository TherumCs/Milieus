/* Milieus by Therum — admin group builder, registration customizer,
   members tab, default-group auto-save. Plain ES5+ for wp-admin. */
(function() {
	'use strict';

	var ROLES = window.MilieusRoles || {};
	var AJAX  = window.MilieusAjax || (window.ajaxurl || '/wp-admin/admin-ajax.php');
	var SITE_URL = window.MilieusSiteUrl || '/register/';

	var builder = document.querySelector('[data-milieus-role-builder]');
	if (!builder) return;

	var nonce         = builder.getAttribute('data-nonce');
	var membersNonce  = builder.getAttribute('data-members-nonce');
	var editor        = builder.querySelector('[data-role-editor]');
	var resultEl      = builder.querySelector('[data-role-result]');
	var membersSection= builder.querySelector('[data-members-section]');
	var currentKey    = '';

	function $(s, ctx)  { return (ctx || builder).querySelector(s); }
	function $$(s, ctx) { return Array.prototype.slice.call((ctx || builder).querySelectorAll(s)); }
	function val(sel)   { var el = $(sel); return el ? el.value : ''; }
	function setVal(sel, v) { var el = $(sel); if (el) el.value = (v == null ? '' : v); }
	function setSeg(group, value) {
		// Activate the segmented label whose input.value === value.
		var seg = builder.querySelector('[data-segmented="' + group + '"]');
		if (!seg) return;
		$$('label', seg).forEach(function(lab) {
			var input = lab.querySelector('input');
			var active = input && input.value === value;
			lab.classList.toggle('is-active', active);
			if (input) input.checked = active;
		});
		// Trigger detail panel visibility
		var detail = seg.parentElement.querySelector('.th-seg-detail');
		if (detail) {
			var offValues = ['forever', 'permanent', 'off'];
			detail.hidden = offValues.indexOf(value) >= 0;
		}
	}

	// ── Open / close editor ────────────────────────────────────────────
	function openEditor(role) {
		editor.hidden = false;
		resultEl.textContent = '';
		var isNew = !role;
		currentKey = isNew ? '' : role.key;

		$('[data-role-editor-title]').textContent = isNew ? 'New group' : 'Edit group: ' + role.name;
		setVal('[data-role-key]', isNew ? '' : role.key);
		setVal('[data-role-is-new]', isNew ? '1' : '0');
		setVal('[data-role-name]', isNew ? '' : role.name);
		setVal('[data-role-discount]', isNew ? 0 : role.discount);

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

		// Group lifetime
		if (role && role.expires_at > 0) {
			setSeg('role-life', 'date');
			var d = new Date(role.expires_at * 1000);
			setVal('[data-role-expires-date]', d.toISOString().slice(0, 10));
		} else {
			setSeg('role-life', 'forever');
			setVal('[data-role-expires-date]', '');
		}

		// Member duration
		var dur = (role && role.member_duration) || { value: 0, unit: 'days' };
		if (dur.value > 0) {
			setSeg('member-life', 'duration');
			setVal('[data-duration-value]', dur.value);
			setVal('[data-duration-unit]', dur.unit);
		} else {
			setSeg('member-life', 'permanent');
			setVal('[data-duration-value]', 30);
			setVal('[data-duration-unit]', 'days');
		}

		// Registration link
		var reg = (role && role.reg) || {};
		setSeg('reg-enabled', reg.enabled ? 'on' : 'off');
		setVal('[data-reg-slug]', reg.slug || '');
		setVal('[data-reg-brand]', reg.brand || '');
		setVal('[data-reg-logo]', reg.logo || '');
		setVal('[data-reg-heading]', reg.heading || '');
		setVal('[data-reg-lede]', reg.lede || '');
		setVal('[data-reg-color]', reg.color || '#2563eb');
		setVal('[data-reg-color-hex]', reg.color || '#2563eb');
		setVal('[data-reg-button]', reg.button || '');
		setVal('[data-reg-redirect]', reg.redirect || '');
		setVal('[data-reg-max-signups]', reg.max_signups || 0);
		$$('[data-reg-extra]').forEach(function(cb) {
			cb.checked = reg.extras && reg.extras.indexOf(cb.value) >= 0;
			var p = cb.closest('[data-reg-extra-pill]');
			if (p) p.classList.toggle('active', cb.checked);
		});
		setSeg('bg-kind', reg.bg_kind || 'solid');
		showBgPanel(reg.bg_kind || 'solid');
		setVal('[data-bg-solid]', reg.bg_solid || '#fafaf9');
		setVal('[data-bg-solid-hex]', reg.bg_solid || '#fafaf9');
		setVal('[data-bg-grad-1]', reg.bg_grad_1 || '#fde68a');
		setVal('[data-bg-grad-2]', reg.bg_grad_2 || '#fca5a5');
		setVal('[data-bg-grad-dir]', reg.bg_grad_dir || '135deg');
		setVal('[data-bg-image]', reg.bg_image || '');
		var dim = $('[data-bg-dim]'); if (dim) dim.checked = reg.bg_dim !== false;
		var blur = $('[data-bg-blur]'); if (blur) blur.checked = !!reg.bg_blur;

		applyPreview();
		applyBg();

		$('[data-role-delete]').hidden = isNew;
		membersSection.hidden = isNew;
		if (!isNew) loadMembers();

		editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function closeEditor() { editor.hidden = true; }

	$('[data-role-new]').addEventListener('click', function() { openEditor(null); });
	$$('[data-role-cancel]').forEach(function(b) { b.addEventListener('click', closeEditor); });

	document.addEventListener('click', function(e) {
		var editBtn = e.target.closest('[data-role-edit]');
		if (editBtn) {
			e.preventDefault();
			var k = editBtn.getAttribute('data-role-edit');
			if (ROLES[k]) openEditor(ROLES[k]);
		}
	});

	// ── Bundle / cap toggle + search ───────────────────────────────────
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
		if (t.matches && t.matches('[data-reg-extra]')) {
			var p = t.closest('[data-reg-extra-pill]');
			if (p) p.classList.toggle('active', t.checked);
			applyPreview();
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

	// ── Segmented controls ─────────────────────────────────────────────
	$$('[data-segmented]').forEach(function(seg) {
		seg.querySelectorAll('label').forEach(function(lab) {
			lab.addEventListener('click', function(e) {
				e.preventDefault();
				var input = lab.querySelector('input');
				if (!input) return;
				seg.querySelectorAll('label').forEach(function(o) { o.classList.remove('is-active'); });
				lab.classList.add('is-active');
				input.checked = true;
				var detail = seg.parentElement.querySelector('.th-seg-detail');
				if (detail) {
					var off = ['forever','permanent','off'].indexOf(input.value) >= 0;
					detail.hidden = off;
				}
				// Background-kind switches which sub-panel is visible.
				if (seg.getAttribute('data-segmented') === 'bg-kind') {
					showBgPanel(input.value);
					applyBg();
				}
			});
		});
	});

	function showBgPanel(kind) {
		$$('[data-bg-panel]').forEach(function(p) {
			p.hidden = (p.getAttribute('data-bg-panel') !== kind);
		});
	}

	// ── Live preview wiring ────────────────────────────────────────────
	function applyPreview() {
		setText('[data-prev-brand]', val('[data-reg-brand]') || 'YOUR BRAND');
		setText('[data-prev-heading]', val('[data-reg-heading]') || 'Join the group');
		setText('[data-prev-lede]', val('[data-reg-lede]') || 'Create your account to get started.');
		setText('[data-prev-button]', val('[data-reg-button]') || 'Create account →');

		var logo = val('[data-reg-logo]');
		var img = $('[data-prev-logo]'), brand = $('[data-prev-brand]');
		if (logo) { img.src = logo; img.style.display = 'block'; brand.style.display = 'none'; }
		else { img.style.display = 'none'; brand.style.display = 'block'; }

		var color = val('[data-reg-color-hex]') || val('[data-reg-color]') || '#2563eb';
		var card = $('[data-reg-card]');
		if (/^#[0-9a-f]{6}$/i.test(color)) {
			card.style.setProperty('--reg-accent', color);
		}

		// Extras
		var FIELDS = {
			name:       { label: 'Full name', type: 'text', ph: 'Ada Lovelace' },
			company:    { label: 'Company',   type: 'text', ph: 'Acme' },
			phone:      { label: 'Phone',     type: 'tel',  ph: '(555) 123-4567' },
			referral:   { label: 'Referral code', type: 'text', ph: 'FRIEND2026' },
			'how-heard':{ label: 'How did you hear?', type: 'text', ph: '' },
		};
		var target = $('[data-prev-extras]');
		target.innerHTML = '';
		$$('[data-reg-extra]:checked').forEach(function(cb) {
			var f = FIELDS[cb.value]; if (!f) return;
			target.insertAdjacentHTML('beforeend',
				'<label>' + f.label + '<input type="' + f.type + '" placeholder="' + f.ph + '"></label>');
		});
	}
	function setText(sel, t) { var el = $(sel); if (el) el.textContent = t; }

	['[data-reg-brand]','[data-reg-heading]','[data-reg-lede]','[data-reg-button]','[data-reg-logo]'].forEach(function(s) {
		var el = $(s); if (el) el.addEventListener('input', applyPreview);
	});
	function bindColor(picker, hex) {
		var p = $(picker), h = $(hex);
		if (!p || !h) return;
		p.addEventListener('input', function() { h.value = p.value; applyPreview(); });
		h.addEventListener('input', function() {
			if (/^#[0-9a-f]{6}$/i.test(h.value)) p.value = h.value;
			applyPreview();
		});
	}
	bindColor('[data-reg-color]', '[data-reg-color-hex]');

	// ── Background ─────────────────────────────────────────────────────
	function activeBgKind() {
		var checked = builder.querySelector('[data-segmented="bg-kind"] input:checked');
		return checked ? checked.value : 'solid';
	}
	function applyBg() {
		var stage = $('[data-reg-stage]');
		if (!stage) return;
		var kind = activeBgKind();
		if (kind === 'solid') {
			stage.style.background = val('[data-bg-solid-hex]') || '#fafaf9';
		} else if (kind === 'gradient') {
			var c1 = val('[data-bg-grad-1]'), c2 = val('[data-bg-grad-2]'), dir = val('[data-bg-grad-dir]');
			stage.style.background = dir === 'radial'
				? 'radial-gradient(circle at center, ' + c1 + ', ' + c2 + ')'
				: 'linear-gradient(' + dir + ', ' + c1 + ', ' + c2 + ')';
		} else {
			var url = val('[data-bg-image]');
			var dim = $('[data-bg-dim]') && $('[data-bg-dim]').checked;
			var overlay = dim ? 'linear-gradient(rgba(0,0,0,.3),rgba(0,0,0,.3)), ' : '';
			stage.style.background = url ? overlay + 'url("' + url + '") center/cover no-repeat' : '#fafaf9';
		}
	}
	bindColor('[data-bg-solid]', '[data-bg-solid-hex]');
	['[data-bg-solid-hex]','[data-bg-grad-1]','[data-bg-grad-2]','[data-bg-grad-dir]','[data-bg-image]'].forEach(function(s) {
		var el = $(s); if (el) el.addEventListener('input', applyBg);
	});
	$$('[data-bg-dim],[data-bg-blur]').forEach(function(el) { el.addEventListener('change', applyBg); });

	// Copy link
	var copyBtn = $('[data-reg-copy]');
	if (copyBtn) {
		copyBtn.addEventListener('click', function() {
			var url = SITE_URL + (val('[data-reg-slug]') || '');
			if (navigator.clipboard) navigator.clipboard.writeText(url);
			var orig = copyBtn.textContent;
			copyBtn.textContent = '✓ Copied';
			setTimeout(function() { copyBtn.textContent = orig; }, 1200);
		});
	}

	// ── Save / Delete ──────────────────────────────────────────────────
	function collectFormData() {
		var fd = new FormData();
		fd.append('action', 'milieus_role_save');
		fd.append('nonce', nonce);
		fd.append('key',      val('[data-role-key]'));
		fd.append('name',     val('[data-role-name]'));
		fd.append('is_new',   val('[data-role-is-new]'));
		fd.append('discount', val('[data-role-discount]'));

		$$('[data-bundle]:checked').forEach(function(c) { fd.append('bundles[]', c.value); });
		$$('[data-cap]:checked').forEach(function(c) { fd.append('caps[]', c.value); });

		// Group lifetime
		var roleLife = builder.querySelector('[data-segmented="role-life"] input:checked');
		if (roleLife && roleLife.value === 'date') {
			var dateStr = val('[data-role-expires-date]');
			if (dateStr) {
				// End-of-day UTC
				var ts = Math.floor(new Date(dateStr + 'T23:59:59Z').getTime() / 1000);
				fd.append('expires_at', ts);
			} else {
				fd.append('expires_at', 0);
			}
		} else {
			fd.append('expires_at', 0);
		}

		// Member duration
		var memberLife = builder.querySelector('[data-segmented="member-life"] input:checked');
		if (memberLife && memberLife.value === 'duration') {
			fd.append('duration_value', val('[data-duration-value]'));
			fd.append('duration_unit', val('[data-duration-unit]'));
		} else {
			fd.append('duration_value', 0);
			fd.append('duration_unit', 'days');
		}

		// Registration link
		var regOn = builder.querySelector('[data-segmented="reg-enabled"] input:checked');
		fd.append('reg[enabled]', regOn && regOn.value === 'on' ? '1' : '');
		fd.append('reg[slug]',     val('[data-reg-slug]'));
		fd.append('reg[brand]',    val('[data-reg-brand]'));
		fd.append('reg[logo]',     val('[data-reg-logo]'));
		fd.append('reg[heading]',  val('[data-reg-heading]'));
		fd.append('reg[lede]',     val('[data-reg-lede]'));
		fd.append('reg[color]',    val('[data-reg-color-hex]'));
		fd.append('reg[button]',   val('[data-reg-button]'));
		fd.append('reg[redirect]', val('[data-reg-redirect]'));
		var approval = builder.querySelector('input[name="reg-approval"]:checked');
		fd.append('reg[approval]', approval && approval.value === 'on' ? '1' : '');
		fd.append('reg[max_signups]', val('[data-reg-max-signups]'));
		$$('[data-reg-extra]:checked').forEach(function(c) { fd.append('reg[extras][]', c.value); });

		// Background
		var bgKind = activeBgKind();
		fd.append('reg[bg_kind]', bgKind);
		fd.append('reg[bg_solid]', val('[data-bg-solid-hex]'));
		fd.append('reg[bg_grad_1]', val('[data-bg-grad-1]'));
		fd.append('reg[bg_grad_2]', val('[data-bg-grad-2]'));
		fd.append('reg[bg_grad_dir]', val('[data-bg-grad-dir]'));
		fd.append('reg[bg_image]', val('[data-bg-image]'));
		fd.append('reg[bg_dim]',  $('[data-bg-dim]') && $('[data-bg-dim]').checked ? '1' : '');
		fd.append('reg[bg_blur]', $('[data-bg-blur]') && $('[data-bg-blur]').checked ? '1' : '');

		return fd;
	}

	$('[data-role-save]').addEventListener('click', function() {
		var btn = this;
		btn.disabled = true; btn.style.opacity = '0.6';
		resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx2,#666)';

		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: collectFormData() })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				btn.disabled = false; btn.style.opacity = '';
				if (j && j.success) {
					resultEl.textContent = '✓ ' + (j.data.msg || 'Saved');
					resultEl.style.color = 'var(--ok,#16a34a)';
					setTimeout(function() { location.reload(); }, 700);
				} else {
					resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
					resultEl.style.color = 'var(--err,#dc2626)';
				}
			})
			.catch(function() {
				btn.disabled = false; btn.style.opacity = '';
				resultEl.textContent = '✗ Network error';
				resultEl.style.color = 'var(--err,#dc2626)';
			});
	});

	$('[data-role-delete]').addEventListener('click', function() {
		var key  = val('[data-role-key]');
		var name = val('[data-role-name]');
		if (!key) return;
		if (!confirm('Delete group "' + name + '"? Members will revert to the default group.')) return;

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
					resultEl.style.color = 'var(--ok,#16a34a)';
					setTimeout(function() { location.reload(); }, 800);
				} else {
					resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
					resultEl.style.color = 'var(--err,#dc2626)';
				}
			});
	});

	// ── Members tab ────────────────────────────────────────────────────
	function loadMembers() {
		var fd = new FormData();
		fd.append('action', 'milieus_members_list');
		fd.append('nonce', membersNonce);
		fd.append('key', currentKey);
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (!j || !j.success) return;
				$('[data-members-count]').textContent = j.data.total;
				var tbody = $('[data-members-tbody]');
				tbody.innerHTML = '';
				if (!j.data.rows.length) {
					tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--tx3);padding:20px">No members yet — search above to add one.</td></tr>';
					return;
				}
				j.data.rows.forEach(function(m) { tbody.appendChild(memberRow(m)); });
				updateBulk();
			});
	}

	function memberRow(m) {
		var tr = document.createElement('tr');
		tr.dataset.userId = m.id;
		var tierClass = '';
		if (m.expires_tier === 'soon')    tierClass = ' th-tag-time-soon';
		if (m.expires_tier === 'urgent')  tierClass = ' th-tag-time-urgent';
		if (m.expires_tier === 'expired') tierClass = ' th-tag-time-expired';
		var expires = m.expires_at === 0
			? '<span class="th-roles-caps">Permanent</span>'
			: '<span class="th-tag-time' + tierClass + '">' + escapeHtml(m.expires_label) + '</span>';
		tr.innerHTML =
			'<td class="check"><input type="checkbox" data-member-check></td>' +
			'<td><div class="who"><div class="th-avatar"><img src="' + escapeHtml(m.avatar_url) + '"></div><div><strong>' + escapeHtml(m.name) + '</strong><small>' + escapeHtml(m.email) + '</small></div></div></td>' +
			'<td class="th-roles-caps">' + escapeHtml(m.assigned_label) + '</td>' +
			'<td>' + expires + '</td>' +
			'<td><span class="th-source-tag th-source-' + escapeHtml(m.source) + '">' + escapeHtml(m.source) + '</span></td>' +
			'<td style="text-align:right"><button class="th-link-btn danger" data-member-revoke>Remove</button></td>';
		tr.querySelector('[data-member-check]').addEventListener('change', updateBulk);
		tr.querySelector('[data-member-revoke]').addEventListener('click', function() {
			if (!confirm('Remove ' + m.name + ' from this group?')) return;
			bulkAction('revoke', [m.id], function() { loadMembers(); });
		});
		return tr;
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
		});
	}

	// Bulk select
	function updateBulk() {
		var checked = $$('[data-member-check]:checked');
		var bar = $('[data-bulk-bar]');
		bar.hidden = checked.length === 0;
		$('[data-bulk-count]').textContent = checked.length;
	}
	$('[data-check-all]').addEventListener('change', function(e) {
		$$('[data-member-check]').forEach(function(cb) { cb.checked = e.target.checked; });
		updateBulk();
	});
	$$('[data-bulk-action]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var action = btn.getAttribute('data-bulk-action');
			var ids = $$('[data-member-check]:checked').map(function(cb) {
				return parseInt(cb.closest('tr').dataset.userId, 10);
			});
			if (!ids.length) return;
			if (action === 'revoke' && !confirm('Remove ' + ids.length + ' member(s) from this group?')) return;
			bulkAction(action, ids, loadMembers);
		});
	});

	function bulkAction(action, ids, cb) {
		var fd = new FormData();
		fd.append('action', 'milieus_members_bulk');
		fd.append('nonce', membersNonce);
		fd.append('key', currentKey);
		fd.append('bulk_action', action);
		ids.forEach(function(id) { fd.append('user_ids[]', id); });
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) { if (cb) cb(j); });
	}

	// Search-add
	var searchInput = $('[data-member-search]');
	var searchResults = $('[data-member-search-results]');
	var searchTimer = null;

	searchInput.addEventListener('input', function() {
		clearTimeout(searchTimer);
		var q = searchInput.value.trim();
		if (q.length < 2) { searchResults.hidden = true; return; }
		searchTimer = setTimeout(function() { runSearch(q); }, 200);
	});
	document.addEventListener('click', function(e) {
		if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) searchResults.hidden = true;
	});

	function runSearch(q) {
		var fd = new FormData();
		fd.append('action', 'milieus_members_search');
		fd.append('nonce', membersNonce);
		fd.append('key', currentKey);
		fd.append('q', q);
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (!j || !j.success) return;
				searchResults.innerHTML = '';
				if (!j.data.length) {
					searchResults.innerHTML = '<div class="th-member-search-result"><div class="who"><small>No matches</small></div></div>';
				} else {
					j.data.forEach(function(u) {
						var initials = (u.name || u.email).split(' ').map(function(w){return w[0];}).join('').slice(0,2).toUpperCase();
						var row = document.createElement('div');
						row.className = 'th-member-search-result';
						row.dataset.userId = u.id;
						row.innerHTML = '<div class="th-avatar">' + escapeHtml(initials) + '</div><div class="who"><strong>' + escapeHtml(u.name) + '</strong><small>' + escapeHtml(u.email) + '</small></div><span class="role-tag">' + escapeHtml(u.role) + '</span>';
						row.addEventListener('click', function() {
							addMember(u.id);
							searchInput.value = '';
							searchResults.hidden = true;
						});
						searchResults.appendChild(row);
					});
				}
				searchResults.hidden = false;
			});
	}

	function addMember(uid) {
		var fd = new FormData();
		fd.append('action', 'milieus_members_add');
		fd.append('nonce', membersNonce);
		fd.append('key', currentKey);
		fd.append('user_id', uid);
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function() { loadMembers(); });
	}

	// ── Auto-saving select (default group) ─────────────────────────────
	document.addEventListener('change', function(e) {
		var sel = e.target.closest('[data-milieus-select]');
		if (!sel) return;
		var name  = sel.getAttribute('data-milieus-select');
		var nonceVal = sel.getAttribute('data-nonce');
		var resultEl = sel.parentElement && sel.parentElement.querySelector('[data-milieus-select-result]');
		var action = name === 'default_role' ? 'milieus_default_role_save' : null;
		if (!action) return;
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', nonceVal);
		fd.append('value', sel.value);
		if (resultEl) { resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx3,#999)'; }
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (!resultEl) return;
				if (j && j.success) {
					resultEl.textContent = '✓ Saved';
					resultEl.style.color = 'var(--ok,#16a34a)';
					setTimeout(function() { resultEl.textContent = ''; }, 1500);
				} else {
					resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
					resultEl.style.color = 'var(--err,#dc2626)';
				}
			});
	});
})();
