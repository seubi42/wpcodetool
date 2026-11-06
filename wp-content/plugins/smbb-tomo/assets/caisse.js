/**
 * Caisse de la feuille d'appel : catalogues en onglets, produits en tuiles, panier, client et
 * paiement (sur place ou lien par SMS). Données : window.tomoCaisse (OnsiteSales::register).
 */
(function () {
	'use strict';

	var data = window.tomoCaisse;
	var root = document.querySelector('[data-tomo-pos]');

	if (!data || !root) {
		return;
	}

	var t = data.texts || {};
	var form = root.querySelector('form');
	var tabsBox = root.querySelector('[data-pos-tabs]');
	var search = root.querySelector('[data-pos-search]');
	var grid = root.querySelector('[data-pos-grid]');
	var linesBox = root.querySelector('[data-pos-lines]');
	var emptyBox = root.querySelector('[data-pos-empty]');
	var totalBox = root.querySelector('[data-pos-total]');
	var qtyBox = root.querySelector('[data-pos-qty]');
	var title = root.querySelector('[data-pos-title]');
	var customer = root.querySelector('[data-pos-customer]');
	var customerLabel = root.querySelector('[data-pos-customer-label]');
	var inscription = root.querySelector('[data-pos-inscription]');
	var fields = {};
	var products = {};
	var cart = {};
	var order = [];
	var current = 0;
	var lastFocus = null;
	var fmt;

	try {
		fmt = new Intl.NumberFormat(document.documentElement.lang || 'fr-FR', { style: 'currency', currency: data.currency || 'EUR' });
	} catch (e) {
		fmt = { format: function (n) { return n.toFixed(2).replace('.', ',') + ' €'; } };
	}

	root.querySelectorAll('[data-pos-field]').forEach(function (input) {
		fields[input.getAttribute('data-pos-field')] = input;
	});

	(data.products || []).forEach(function (p) {
		products[p.id] = p;
	});

	function sprintf(text, value) {
		return String(text || '').replace('%s', value);
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);

		if (cls) {
			node.className = cls;
		}

		if (text !== undefined && text !== null) {
			node.textContent = text;
		}

		return node;
	}

	// Un onglet parent montre aussi les produits de ses sous-catalogues.
	function family(id) {
		var ids = [id];
		var added = true;

		while (added) {
			added = false;
			(data.tabs || []).forEach(function (tab) {
				if (ids.indexOf(tab.parent) !== -1 && ids.indexOf(tab.id) === -1) {
					ids.push(tab.id);
					added = true;
				}
			});
		}

		return ids;
	}

	function renderTabs() {
		tabsBox.innerHTML = '';
		var tabs = [{ id: 0, name: t.all || 'Tout', depth: 0 }].concat(data.tabs || []);

		if (tabs.length < 3) {
			tabsBox.hidden = true;
			return;
		}

		tabs.forEach(function (tab) {
			var b = el('button', 'tomo-pos-tab' + (tab.depth ? ' is-sub' : ''), (tab.depth ? '› ' : '') + tab.name);
			b.type = 'button';
			b.setAttribute('role', 'tab');
			b.setAttribute('aria-selected', tab.id === current ? 'true' : 'false');
			b.addEventListener('click', function () {
				current = tab.id;
				renderTabs();
				renderGrid();
			});
			tabsBox.appendChild(b);
		});
	}

	function renderGrid() {
		var q = (search.value || '').trim().toLowerCase();
		var ids = current ? family(current) : null;
		var shown = 0;

		grid.innerHTML = '';

		(data.products || []).forEach(function (p) {
			if (ids && !p.cats.some(function (c) { return ids.indexOf(c) !== -1; })) {
				return;
			}

			if (q && (p.name + ' ' + p.sku + ' ' + p.desc).toLowerCase().indexOf(q) === -1) {
				return;
			}

			var out = p.stock !== null && p.stock !== undefined && p.stock <= 0;
			var tile = el('button', 'tomo-pos-tile' + (out ? ' is-out' : ''));
			tile.type = 'button';
			tile.disabled = out;

			var pic = el('span', 'tomo-pos-pic');

			if (p.img) {
				var img = el('img');
				img.src = p.img;
				img.alt = '';
				img.loading = 'lazy';
				pic.appendChild(img);
			} else {
				pic.textContent = p.name.charAt(0).toUpperCase();
			}

			tile.appendChild(pic);
			tile.appendChild(el('span', 'tomo-pos-name', p.name));

			if (p.sku) {
				tile.appendChild(el('span', 'tomo-pos-ref', sprintf(t.ref || 'Réf. %s', p.sku)));
			}

			if (p.desc) {
				tile.appendChild(el('span', 'tomo-pos-desc', p.desc));
			}

			tile.appendChild(el('span', 'tomo-pos-price', out ? (t.noStock || 'Rupture') : fmt.format(p.price)));

			if (cart[p.id]) {
				tile.appendChild(el('span', 'tomo-pos-badge', String(cart[p.id])));
			}

			tile.addEventListener('click', function () {
				add(p.id, 1);
			});
			grid.appendChild(tile);
			shown++;
		});

		if (!shown) {
			grid.appendChild(el('p', 'tomo-pos-none', t.empty || ''));
		}
	}

	function add(id, delta) {
		var p = products[id];
		var qty = (cart[id] || 0) + delta;

		if (p && p.stock !== null && p.stock !== undefined && qty > p.stock) {
			qty = p.stock;
		}

		if (qty <= 0) {
			delete cart[id];
			order = order.filter(function (x) { return x !== id; });
		} else {
			if (!cart[id]) {
				order.push(id);
			}

			cart[id] = qty;
		}

		renderCart();
		renderGrid();
	}

	function renderCart() {
		var total = 0;

		linesBox.innerHTML = '';
		order.forEach(function (id) {
			var p = products[id];
			var li = el('li', 'tomo-pos-line');
			var minus = el('button', 'tomo-pos-step', '−');
			var plus = el('button', 'tomo-pos-step', '+');

			minus.type = plus.type = 'button';
			minus.setAttribute('aria-label', '−1 ' + p.name);
			plus.setAttribute('aria-label', '+1 ' + p.name);
			minus.addEventListener('click', function () { add(id, -1); });
			plus.addEventListener('click', function () { add(id, 1); });

			li.appendChild(el('span', 'tomo-pos-line-name', p.name));
			var qty = el('span', 'tomo-pos-qty');
			qty.appendChild(minus);
			qty.appendChild(el('strong', '', String(cart[id])));
			qty.appendChild(plus);
			li.appendChild(qty);
			li.appendChild(el('span', 'tomo-pos-line-total', fmt.format(p.price * cart[id])));
			linesBox.appendChild(li);
			total += p.price * cart[id];
		});

		emptyBox.hidden = order.length > 0;
		totalBox.textContent = fmt.format(total);
	}

	function updateCustomerLabel() {
		var name = ((fields.first ? fields.first.value : '') + ' ' + (fields.last ? fields.last.value : '')).trim();
		var contact = (fields.phone && fields.phone.value) || (fields.email && fields.email.value) || '';
		customerLabel.textContent = (t.client || 'Client') + (name || contact ? ' · ' + [name, contact].filter(Boolean).join(' · ') : '');
	}

	function open(button) {
		var d = button ? button.dataset : {};
		var name = ((d.first || '') + ' ' + (d.last || '')).trim();

		cart = {};
		order = [];
		current = 0;
		if (errorBox) {
			errorBox.hidden = true;
		}
		search.value = '';
		inscription.value = d.inscription || '';
		['first', 'last', 'email', 'phone'].forEach(function (key) {
			if (fields[key]) {
				fields[key].value = d[key] || '';
			}
		});
		title.textContent = name ? sprintf(t.saleFor || '%s', name) : (t.newSale || '');
		customer.open = !d.inscription;
		updateCustomerLabel();
		renderTabs();
		renderGrid();
		renderCart();

		lastFocus = document.activeElement;
		root.hidden = false;
		document.documentElement.classList.add('tomo-pos-open');
		if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
			search.focus({ preventScroll: true });
		}
	}

	function close() {
		root.hidden = true;
		document.documentElement.classList.remove('tomo-pos-open');

		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-tomo-sale]');

		if (trigger) {
			e.preventDefault();
			open(trigger);
		}
	});

	root.querySelectorAll('[data-pos-close]').forEach(function (b) {
		b.addEventListener('click', close);
	});

	root.addEventListener('click', function (e) {
		if (e.target === root) {
			close();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !root.hidden) {
			close();
		}
	});

	search.addEventListener('input', renderGrid);
	search.addEventListener('keydown', function (e) {
		// Entrée dans la recherche : ajoute le seul produit trouvé, sans valider la vente.
		if (e.key === 'Enter') {
			e.preventDefault();
			var tiles = grid.querySelectorAll('.tomo-pos-tile:not([disabled])');

			if (tiles.length === 1) {
				tiles[0].click();
			}
		}
	});

	Object.keys(fields).forEach(function (key) {
		fields[key].addEventListener('input', updateCustomerLabel);
	});

	var errorBox = root.querySelector('[data-pos-error]');
	var ajax = data.ajax || {};
	var busy = false;

	function showError(text) {
		if (!errorBox) {
			toast(text, 'error');
			return;
		}

		errorBox.textContent = text || '';
		errorBox.hidden = !text;
	}

	function fillQty() {
		qtyBox.innerHTML = '';
		order.forEach(function (id) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'qty[' + id + ']';
			input.value = String(cart[id]);
			qtyBox.appendChild(input);
		});
	}

	form.addEventListener('submit', function (e) {
		var mode = e.submitter && e.submitter.value === 'link' ? 'link' : 'paid';

		showError('');

		if (!order.length) {
			e.preventDefault();
			showError(t.pickOne || '');
			return;
		}

		// Pas de vente sans compte client : e-mail obligatoire, sauf participant déjà rattaché.
		if (!inscription.value && !(fields.email && fields.email.value.trim())) {
			e.preventDefault();
			customer.open = true;
			showError(t.needEmail || '');

			if (fields.email) {
				fields.email.focus();
			}

			return;
		}

		if (mode === 'link' && !(fields.phone && fields.phone.value.trim()) && !(fields.email && fields.email.value.trim())) {
			e.preventDefault();
			customer.open = true;
			showError(t.needContact || '');

			if (fields.phone) {
				fields.phone.focus();
			}

			return;
		}

		fillQty();

		// Sans Ajax configuré : envoi classique (la page se recharge).
		if (!ajax.action || !window.fetch || !window.FormData) {
			return;
		}

		e.preventDefault();

		if (busy) {
			return;
		}

		var body = new window.FormData(form);
		body.set('mode', mode);
		var buttons = form.querySelectorAll('[data-pos-submit]');
		var label = e.submitter ? e.submitter.innerHTML : '';

		busy = true;
		buttons.forEach(function (b) { b.disabled = true; });

		if (e.submitter) {
			e.submitter.textContent = t.sending || '…';
		}

		send('sale', body).then(function (res) {
			if (res.success) {
				close();
				toast(res.message, 'success');
			} else {
				showError(res.message);
			}
		}).catch(function () {
			showError(t.network || '');
		}).then(function () {
			busy = false;
			buttons.forEach(function (b) { b.disabled = false; });

			if (e.submitter) {
				e.submitter.innerHTML = label;
			}
		});
	});

	// ------------------------------------------------------------------
	// Ajax commun : nouvelle vente, retour, recherche et pages de l'onglet Caisse.
	// ------------------------------------------------------------------

	var panel = document.querySelector('[data-tomo-caisse-panel]');
	var state = { q: '', p: 1 };

	(function () {
		var params = new window.URLSearchParams(window.location.search);
		state.q = params.get('q') || '';
		state.p = parseInt(params.get('p') || '1', 10) || 1;
	}());

	function send(op, body) {
		body = body || new window.FormData();
		body.set('action', ajax.action);
		body.set('nonce', ajax.nonce);
		body.set('creneau', String(ajax.creneau));
		body.set('op', op);
		body.set('hors_date', window.tomoForce ? '1' : '0');
		body.set('q', state.q);
		body.set('p', String(state.p));

		if (panel) {
			panel.classList.add('is-loading');
		}

		return window.fetch(ajax.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		}).then(function (json) {
			var d = (json && json.data) || {};

			if (panel && d.panel !== undefined) {
				var focusQ = document.activeElement && document.activeElement.matches && document.activeElement.matches('[data-tomo-caisse-q]');
				var caret = focusQ ? document.activeElement.selectionStart : null;
				panel.innerHTML = d.panel;
				applyForce(panel);
				syncReturns(panel);

				if (focusQ) {
					var q = panel.querySelector('[data-tomo-caisse-q]');

					if (q) {
						q.focus();

						try { q.setSelectionRange(caret, caret); } catch (err) { /* type search */ }
					}
				}
			}

			if (d.tab !== undefined) {
				document.querySelectorAll('[data-tomo-caisse-tab]').forEach(function (n) { n.textContent = d.tab; });
			}

			if (d.bar !== undefined) {
				document.querySelectorAll('[data-tomo-caisse-bar]').forEach(function (n) { n.textContent = d.bar; });
			}

			return { success: !!(json && json.success), message: d.message || '' };
		}).finally(function () {
			if (panel) {
				panel.classList.remove('is-loading');
			}
		});
	}

	// « Accès forcé » (hors du jour de l'atelier) : le panneau rechargé suit l'interrupteur.
	function applyForce(scope) {
		var sw = document.querySelector('[data-tomo-force-switch]');

		if (!sw) {
			return;
		}

		(scope || document).querySelectorAll('[data-tomo-lockable]').forEach(function (el) {
			el.disabled = !sw.checked;
		});
		(scope || document).querySelectorAll('[data-tomo-force]').forEach(function (el) {
			el.value = sw.checked ? '1' : '0';
		});
	}

	function updateUrl() {
		if (!window.history || !window.history.replaceState || !ajax.baseUrl) {
			return;
		}

		var url = new window.URL(ajax.baseUrl, window.location.href);
		var current = document.querySelector('[data-tomo-panel="caisse"]');

		if (current && !current.hidden) {
			url.searchParams.set('onglet', 'caisse');

			if (state.q) {
				url.searchParams.set('q', state.q);
			}

			if (state.p > 1) {
				url.searchParams.set('p', String(state.p));
			}
		}

		try {
			window.history.replaceState(null, '', url.toString());
		} catch (err) { /* autre origine */ }
	}

	function refreshPanel() {
		return send('panel').then(updateUrl).catch(function () {
			toast(t.network || '', 'error');
		});
	}

	// Onglets sans rechargement.
	function showTab(name) {
		var tabs = document.querySelectorAll('[data-tomo-tabs] [data-tomo-tab]');

		if (!tabs.length) {
			return false;
		}

		tabs.forEach(function (tab) {
			var on = tab.getAttribute('data-tomo-tab') === name;
			tab.classList.toggle('is-active', on);
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		document.querySelectorAll('[data-tomo-panel]').forEach(function (p) {
			p.hidden = p.getAttribute('data-tomo-panel') !== name;
		});
		updateUrl();
		window.scrollTo({ top: 0, behavior: 'smooth' });

		return true;
	}

	document.addEventListener('click', function (e) {
		var tab = e.target.closest('[data-tomo-tab]');

		if (tab && !e.metaKey && !e.ctrlKey && showTab(tab.getAttribute('data-tomo-tab'))) {
			e.preventDefault();
			return;
		}

		var pageLink = e.target.closest('[data-tomo-caisse-page]');

		if (pageLink && panel && ajax.action) {
			e.preventDefault();
			state.p = parseInt(pageLink.getAttribute('data-tomo-caisse-page'), 10) || 1;

			if (pageLink.hasAttribute('data-tomo-caisse-clear')) {
				state.q = '';
			}

			refreshPanel().then(function () {
				var top = panel.querySelector('.tomo-caisse-find');

				if (top && top.getBoundingClientRect().top < 0) {
					top.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			});
		}
	});

	// Recherche instantanée.
	var searchTimer = null;

	document.addEventListener('input', function (e) {
		if (!e.target.matches('[data-tomo-caisse-q]') || !ajax.action) {
			return;
		}

		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(function () {
			state.q = e.target.value.trim();
			state.p = 1;
			refreshPanel();
		}, 250);
	});

	document.addEventListener('submit', function (e) {
		if (e.target.matches('[data-tomo-caisse-find]') && ajax.action) {
			e.preventDefault();
			var q = e.target.querySelector('[data-tomo-caisse-q]');
			state.q = q ? q.value.trim() : '';
			state.p = 1;
			refreshPanel();
		}
	});

	// Retour en bon d'achat : cases à cocher, quantité, confirmation maison, envoi Ajax.
	function syncReturn(f) {
		var acts = f.querySelector('[data-tomo-return-actions]');
		var n = 0;

		f.querySelectorAll('[data-tomo-return-line]').forEach(function (cb) {
			var li = cb.closest('li');
			var q = li ? li.querySelector('[name^="lines["]') : null;

			if (q) {
				q.disabled = !cb.checked;

				if (q.tagName === 'SELECT') {
					q.hidden = !cb.checked;
				}
			}

			if (cb.checked) {
				n++;
			}
		});

		if (acts) {
			acts.hidden = !n;
		}
	}

	function syncReturns(scope) {
		(scope || document).querySelectorAll('[data-tomo-return-form]').forEach(syncReturn);
	}

	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-tomo-return-line]')) {
			syncReturn(e.target.closest('form'));
		}
	});

	document.addEventListener('submit', function (e) {
		var f = e.target.closest('[data-tomo-return-form]');

		if (!f || e.target !== f) {
			return;
		}

		e.preventDefault();

		var lines = [];
		var total = 0;

		f.querySelectorAll('[data-tomo-return-line]:checked').forEach(function (cb) {
			var li = cb.closest('li');
			var q = li ? li.querySelector('[name^="lines["]') : null;
			var qty = q ? parseInt(q.value, 10) || 1 : 1;
			var unit = parseFloat(cb.getAttribute('data-unit') || '0') || 0;

			lines.push({ name: cb.getAttribute('data-name') || '', qty: qty, amount: unit * qty });
			total += unit * qty;
		});

		if (!lines.length) {
			return;
		}

		var sendBox = f.querySelector('[name="send"]');
		var contact = f.getAttribute('data-contact') || '';
		var html = '<p>' + esc(sprintf2(t.askIntro || '%1$s, %2$s', f.getAttribute('data-client') || '', f.getAttribute('data-order') || '')) + '</p>'
			+ '<ul class="tomo-ask-lines">' + lines.map(function (l) {
				return '<li><span>' + esc(l.name) + ' × ' + l.qty + '</span><strong>' + esc(fmt.format(l.amount)) + '</strong></li>';
			}).join('') + '</ul>'
			+ '<p class="tomo-ask-total">' + esc(sprintf(t.askTotal || '%s', fmt.format(total))) + '</p>'
			+ '<p class="tomo-muted">' + esc(sendBox && sendBox.checked ? sprintf(t.askSend || '%s', contact) : (t.askNoSend || '')) + ' ' + esc(t.askStock || '') + '</p>';

		ask(t.askTitle || '', html, t.askOk || 'OK').then(function (ok) {
			if (!ok) {
				return;
			}

			if (!ajax.action || !window.fetch) {
				f.submit();
				return;
			}

			var button = f.querySelector('[data-tomo-return-submit]');

			if (button) {
				button.disabled = true;
				button.textContent = t.sending || '…';
			}

			send('refund', new window.FormData(f)).then(function (res) {
				toast(res.message || (t.returned || ''), res.success ? 'success' : 'error');
			}).catch(function () {
				toast(t.network || '', 'error');

				if (button) {
					button.disabled = false;
				}
			});
		});
	});

	// Annuler une vente pas encore payée.
	document.addEventListener('click', function (e) {
		var link = e.target.closest('[data-tomo-cancel-order]');

		if (!link || !ajax.action || !window.fetch) {
			return;
		}

		e.preventDefault();
		var html = '<p>' + esc(sprintf2(t.askIntro || '%1$s, %2$s', link.getAttribute('data-client') || '', link.getAttribute('data-order') || '')) + ' · <strong>' + esc(link.getAttribute('data-total') || '') + '</strong></p>'
			+ '<p class="tomo-muted">' + esc(t.cancelText || '') + '</p>';

		ask(t.cancelTitle || '', html, t.cancelOk || 'OK', true).then(function (ok) {
			if (!ok) {
				return;
			}

			var body = new window.FormData();
			body.set('id', link.getAttribute('data-tomo-cancel-order'));
			link.classList.add('disabled');
			send('cancel', body).then(function (res) {
				toast(res.message, res.success ? 'success' : 'error');

				// Imprévu en attente (onglet Participants) : la ligne disparaît aussi.
				if (res.success && (!panel || !panel.contains(link))) {
					var row = link.closest('li');
					var box = row ? row.closest('.tomo-walkin-pending') : null;

					if (row) {
						row.remove();
					}

					if (box && !box.querySelector('li')) {
						box.remove();
					}
				}
			}).catch(function () {
				toast(t.network || '', 'error');
			});
		});
	});

	syncReturns(document);

	// ------------------------------------------------------------------
	// Confirmation et messages (pas de fenêtre du navigateur).
	// ------------------------------------------------------------------

	var askBox = document.querySelector('[data-tomo-ask]');
	var toasts = document.querySelector('[data-tomo-toasts]');

	function esc(text) {
		var div = document.createElement('div');
		div.textContent = text === undefined || text === null ? '' : String(text);

		return div.innerHTML;
	}

	function sprintf2(text, a, b) {
		return String(text || '').replace('%1$s', a).replace('%2$s', b);
	}

	function ask(title, html, okLabel, danger) {
		if (!askBox) {
			return Promise.resolve(window.confirm(title));
		}

		return new Promise(function (resolve) {
			var ok = askBox.querySelector('[data-ask-ok]');
			var cancel = askBox.querySelector('[data-ask-cancel]');
			var before = document.activeElement;

			askBox.querySelector('[data-ask-title]').textContent = title;
			askBox.querySelector('[data-ask-text]').innerHTML = html;
			ok.textContent = okLabel;
			askBox.classList.toggle('is-danger', !!danger);
			askBox.hidden = false;
			document.documentElement.classList.add('tomo-pos-open');
			ok.focus();

			function done(value) {
				askBox.hidden = true;
				document.documentElement.classList.remove('tomo-pos-open');
				ok.removeEventListener('click', yes);
				cancel.removeEventListener('click', no);
				askBox.removeEventListener('click', outside);
				document.removeEventListener('keydown', key);

				if (before && before.focus) {
					before.focus();
				}

				resolve(value);
			}

			function yes() { done(true); }
			function no() { done(false); }
			function outside(ev) { if (ev.target === askBox) { done(false); } }
			function key(ev) { if (ev.key === 'Escape') { ev.stopPropagation(); done(false); } }

			ok.addEventListener('click', yes);
			cancel.addEventListener('click', no);
			askBox.addEventListener('click', outside);
			document.addEventListener('keydown', key);
		});
	}

	function toast(text, kind) {
		if (!text) {
			return;
		}

		if (!toasts) {
			window.alert(text);
			return;
		}

		var item = document.createElement('div');
		item.className = 'tomo-toast is-' + (kind || 'info');
		item.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		item.textContent = text;
		toasts.appendChild(item);
		window.setTimeout(function () { item.classList.add('is-out'); }, kind === 'error' ? 7000 : 4500);
		window.setTimeout(function () { item.remove(); }, kind === 'error' ? 7500 : 5000);
		item.addEventListener('click', function () { item.remove(); });
	}
}());
