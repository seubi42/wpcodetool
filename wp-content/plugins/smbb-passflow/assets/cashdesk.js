/**
 * Caisse de la feuille d'appel : catalogues en onglets, produits en tuiles, panier, client et
 * paiement (sur place ou lien par SMS). Données : window.passflowCashdesk (OnsiteSales::register).
 */
(function () {
	'use strict';

	var data = window.passflowCashdesk;
	var root = document.querySelector('[data-passflow-pos]');

	if (!data || !root) {
		return;
	}

	// Sortie du contenu de la page : un parent animé (transform) piégerait la modale « fixed »
	// dans son cadre au lieu de l'écran (modale pas plein écran sur mobile, bas coupé).
	// Une couche .passflow-admin vide garde les couleurs (variables --passflow-*) du thème.
	if (!root.closest('.passflow-pos-layer')) {
		var layer = document.createElement('div');
		layer.className = 'passflow-admin passflow-pos-layer';
		document.body.appendChild(layer);
		[root, document.querySelector('[data-passflow-ask]'), document.querySelector('[data-passflow-toasts]')].forEach(function (node) {
			if (node) {
				layer.appendChild(node);
			}
		});
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
	var inscription = root.querySelector('[data-pos-booking]');
	var methodSelect = root.querySelector('[data-pos-method]');
	var modeInput = root.querySelector('[data-pos-mode]');
	var submitButton = root.querySelector('[data-pos-submit]');

	// Un seul bouton : « Envoyer le SMS » pour le lien de paiement, « Valider » pour un paiement sur place.
	function syncSubmit() {
		if (!methodSelect || !submitButton) {
			return;
		}

		var link = methodSelect.value === 'link';

		if (modeInput) {
			modeInput.value = link ? 'link' : 'paid';
		}

		if (!submitButton.disabled) {
			submitButton.textContent = submitButton.getAttribute(link ? 'data-label-link' : 'data-label-paid') || submitButton.textContent;
		}
	}

	if (methodSelect) {
		methodSelect.addEventListener('change', syncSubmit);
	}
	var cartBox = root.querySelector('.passflow-pos-cart');
	var sheet = root.querySelector('[data-pos-sheet]');
	var sheetCount = root.querySelector('[data-pos-count]');
	var sheetTotal = root.querySelector('[data-pos-sheet-total]');

	// Mobile : le détail du panier (lignes + client) se replie sous une barre « Panier · total ».
	function expand(on) {
		if (!sheet || !cartBox) {
			return;
		}

		cartBox.classList.toggle('is-expanded', !!on);
		sheet.setAttribute('aria-expanded', on ? 'true' : 'false');
	}

	if (sheet) {
		sheet.addEventListener('click', function () {
			expand(!cartBox.classList.contains('is-expanded'));
		});
		// Panier déplié en feuille : un toucher sur le magasin derrière la referme (sans ajouter d'article).
		var shop = root.querySelector('.passflow-pos-shop');
		var swallow = false;
		if (shop) {
			shop.addEventListener('pointerdown', function (e) {
				swallow = cartBox.classList.contains('is-expanded');
				if (swallow) {
					e.preventDefault();
					expand(false);
				}
			}, true);
			shop.addEventListener('click', function (e) {
				if (swallow) {
					swallow = false;
					e.preventDefault();
					e.stopPropagation();
				}
			}, true);
		}
	}
	var fields = {};
	var items = {};
	var cart = {};
	var order = [];
	var current = 0;
	var picking = null;
	var lastAdded = null;
	var codes = {};
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

	// Ce qui entre au panier : un produit simple, ou une variante (taille…) d'un produit variable.
	// pool : réserve de stock commune (variantes qui partagent le stock du produit parent).
	(data.products || []).forEach(function (p) {
		if (p.variants) {
			p.variants.forEach(function (v) {
				items[v.key] = { key: v.key, name: p.name + ' – ' + v.label, base: p.name, label: v.label, price: v.price, stock: v.stock, pool: v.pool };

				if (v.code) {
					codes[v.code.toLowerCase()] = v.key;
				}
			});
		} else {
			items[String(p.id)] = { key: String(p.id), name: p.name, base: p.name, label: '', price: p.price, stock: p.stock, pool: String(p.id) };

			if (p.sku) {
				codes[p.sku.toLowerCase()] = String(p.id);
			}
		}
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
			var b = el('button', 'passflow-pos-tab' + (tab.depth ? ' is-sub' : ''), (tab.depth ? '› ' : '') + tab.name);
			b.type = 'button';
			b.setAttribute('role', 'tab');
			b.setAttribute('aria-selected', tab.id === current ? 'true' : 'false');
			b.addEventListener('click', function () {
				current = tab.id;
				picking = null;
				renderTabs();
				renderGrid();
			});
			tabsBox.appendChild(b);
		});
	}

	function hasStock(stock) {
		return stock === null || stock === undefined || stock > 0;
	}

	function inCart(p) {
		if (!p.variants) {
			return cart[String(p.id)] || 0;
		}

		return p.variants.reduce(function (sum, v) { return sum + (cart[v.key] || 0); }, 0);
	}

	// Choix de la variante : une grande touche par taille (ou couleur…), prix et stock dessous.
	function renderVariants(p) {
		var head = el('div', 'passflow-pos-pick-head');
		var back = el('button', 'passflow-pos-back', '‹ ' + (t.back || ''));
		var prices = p.variants.map(function (v) { return v.price; });
		var same = Math.min.apply(null, prices) === Math.max.apply(null, prices);

		back.type = 'button';
		back.addEventListener('click', function () {
			picking = null;
			renderGrid();
		});
		head.appendChild(back);
		head.appendChild(el('strong', 'passflow-pos-pick-title', sprintf(t.pick || '%s', p.name)));
		grid.appendChild(head);

		p.variants.forEach(function (v) {
			var out = !hasStock(v.stock);
			var b = el('button', 'passflow-pos-variant' + (out ? ' is-out' : ''));

			b.type = 'button';
			b.disabled = out;
			b.appendChild(el('span', 'passflow-pos-variant-label', v.label));

			if (v.sku) {
				b.appendChild(el('span', 'passflow-pos-ref', sprintf(t.ref || 'Réf. %s', v.sku)));
			}

			var info = out ? (t.noStock || 'Rupture') : (same ? '' : fmt.format(v.price));

			if (!out && v.stock !== null && v.stock !== undefined && v.stock <= 5) {
				info = (info ? info + ' · ' : '') + sprintf(t.left || '%s', v.stock);
			}

			if (info) {
				b.appendChild(el('span', 'passflow-pos-price', info));
			}

			if (cart[v.key]) {
				b.appendChild(el('span', 'passflow-pos-badge', String(cart[v.key])));
			}

			b.addEventListener('click', function () {
				add(v.key, 1);
			});
			grid.appendChild(b);
		});
	}

	function renderGrid() {
		var q = (search.value || '').trim().toLowerCase();
		var ids = current ? family(current) : null;
		var shown = 0;

		grid.innerHTML = '';
		grid.classList.toggle('is-picking', !!picking);

		if (picking) {
			renderVariants(picking);
			return;
		}

		(data.products || []).forEach(function (p) {
			if (ids && !p.cats.some(function (c) { return ids.indexOf(c) !== -1; })) {
				return;
			}

			var haystack = p.name + ' ' + p.sku + ' ' + p.desc + (p.variants ? ' ' + p.variants.map(function (v) { return v.label + ' ' + v.sku; }).join(' ') : '');

			if (q && haystack.toLowerCase().indexOf(q) === -1) {
				return;
			}

			var out = p.variants ? !p.variants.some(function (v) { return hasStock(v.stock); }) : !hasStock(p.stock);
			var tile = el('button', 'passflow-pos-tile' + (out ? ' is-out' : ''));
			tile.type = 'button';
			tile.disabled = out;

			var pic = el('span', 'passflow-pos-pic');

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
			tile.appendChild(el('span', 'passflow-pos-name', p.name));

			if (p.sku) {
				tile.appendChild(el('span', 'passflow-pos-ref', sprintf(t.ref || 'Réf. %s', p.sku)));
			}

			if (p.desc) {
				tile.appendChild(el('span', 'passflow-pos-desc', p.desc));
			}

			var price = fmt.format(p.price);

			if (p.variants) {
				var prices = p.variants.map(function (v) { return v.price; });
				var min = Math.min.apply(null, prices);

				price = (min === Math.max.apply(null, prices) ? fmt.format(min) : sprintf(t.from || '%s', fmt.format(min))) + ' · ' + sprintf(t.variants || '%s', p.variants.length);
			}

			tile.appendChild(el('span', 'passflow-pos-price', out ? (t.noStock || 'Rupture') : price));

			// Stock restant (affiché sur PDA, voir pda.css) : orange quand il en reste peu.
			if (!out && !p.variants && p.stock !== null && p.stock !== undefined) {
				tile.appendChild(el('span', 'passflow-pos-stock' + (p.stock <= 3 ? ' is-low' : ''), sprintf(t.left || '%s', p.stock)));
			}

			if (inCart(p)) {
				tile.appendChild(el('span', 'passflow-pos-badge', String(inCart(p))));
			}

			tile.addEventListener('click', function () {
				if (!p.variants) {
					add(String(p.id), 1);
					return;
				}

				// Une seule variante disponible : ajoutée directement.
				var avail = p.variants.filter(function (v) { return hasStock(v.stock); });

				if (p.variants.length === 1 && avail.length === 1) {
					add(avail[0].key, 1);
					return;
				}

				picking = p;
				renderGrid();
				grid.scrollTop = 0;
			});
			grid.appendChild(tile);
			shown++;
		});

		if (!shown) {
			grid.appendChild(el('p', 'passflow-pos-none', t.empty || ''));
		}
	}

	function add(id, delta) {
		id = String(id);
		var p = items[id];
		var qty = (cart[id] || 0) + delta;

		if (p && p.stock !== null && p.stock !== undefined) {
			// Stock commun : ce que les autres variantes de la même réserve ont déjà pris.
			var used = order.reduce(function (sum, k) {
				return k !== id && items[k] && items[k].pool === p.pool ? sum + cart[k] : sum;
			}, 0);

			qty = Math.min(qty, p.stock - used);
		}

		if (qty <= 0) {
			delete cart[id];
			order = order.filter(function (x) { return x !== id; });
		} else {
			if (!cart[id]) {
				order.push(id);
			}

			if (delta > 0 && qty > (cart[id] || 0)) {
				lastAdded = id;
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
			var p = items[id];
			var li = el('li', 'passflow-pos-line');
			var minus = el('button', 'passflow-pos-step', '−');
			var plus = el('button', 'passflow-pos-step', '+');

			minus.type = plus.type = 'button';
			minus.setAttribute('aria-label', '−1 ' + p.name);
			plus.setAttribute('aria-label', '+1 ' + p.name);
			minus.addEventListener('click', function () { add(id, -1); });
			plus.addEventListener('click', function () { add(id, 1); });

			var name = el('span', 'passflow-pos-line-name', p.base);

			// Variante bien visible : « M · Bleu » en pastille à côté du nom.
			if (p.label) {
				name.appendChild(document.createTextNode(' '));
				name.appendChild(el('span', 'passflow-pos-line-variant', p.label));
			}

			if (id === lastAdded) {
				li.className += ' is-new';
			}

			li.appendChild(name);
			var qty = el('span', 'passflow-pos-qty');
			qty.appendChild(minus);
			qty.appendChild(el('strong', '', String(cart[id])));
			qty.appendChild(plus);
			li.appendChild(qty);
			li.appendChild(el('span', 'passflow-pos-line-total', fmt.format(p.price * cart[id])));
			linesBox.appendChild(li);
			total += p.price * cart[id];
		});

		emptyBox.hidden = order.length > 0;
		totalBox.textContent = fmt.format(total);

		if (sheet) {
			var count = order.reduce(function (sum, k) { return sum + cart[k]; }, 0);
			sheetTotal.textContent = fmt.format(total);
			sheetCount.textContent = String(count);
			sheetCount.hidden = !count;

			if (lastAdded) {
				sheet.classList.remove('is-bump');
				void sheet.offsetWidth;
				sheet.classList.add('is-bump');
			}
		}

		var fresh = linesBox.querySelector('.is-new');

		if (fresh && fresh.scrollIntoView) {
			fresh.scrollIntoView({ block: 'nearest' });
		}

		lastAdded = null;
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
		picking = null;
		if (errorBox) {
			errorBox.hidden = true;
		}
		search.value = '';
		inscription.value = d.booking || d.inscription || '';
		['first', 'last', 'email', 'phone'].forEach(function (key) {
			if (fields[key]) {
				fields[key].value = d[key] || '';
			}
		});
		title.textContent = name ? sprintf(t.saleFor || '%s', name) : (t.newSale || '');
		customer.open = !inscription.value;

		if (methodSelect) {
			methodSelect.value = 'card';
			syncSubmit();
		}
		expand(false);
		updateCustomerLabel();
		renderTabs();
		renderGrid();
		renderCart();

		paySheet(false);
		fillDay();

		lastFocus = document.activeElement;
		root.hidden = false;
		document.documentElement.classList.add('passflow-pos-open');
		if (!window.matchMedia || !window.matchMedia('(pointer: coarse)').matches) {
			search.focus({ preventScroll: true });
		}
	}

	function close() {
		root.hidden = true;
		document.documentElement.classList.remove('passflow-pos-open');

		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-passflow-sale]');

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
		// Douchette alors que le curseur n'est pas dans un champ : la saisie part dans la recherche.
		if (!root.hidden && e.key.length === 1 && e.key !== ' ' && !e.ctrlKey && !e.metaKey && !e.altKey && !(e.target.closest && e.target.closest('input, select, textarea, [contenteditable]'))) {
			e.preventDefault();
			search.focus({ preventScroll: true });
			search.value += e.key;
			search.dispatchEvent(new window.Event('input'));
			return;
		}

		if (e.key === 'Escape' && !root.hidden && payBox && !payBox.hidden) {
			paySheet(false);
			return;
		}

		if (e.key === 'Escape' && !root.hidden) {
			// Pendant le choix d'une taille, Échap revient d'abord aux produits.
			if (picking) {
				picking = null;
				renderGrid();
				return;
			}

			close();
		}
	});

	search.addEventListener('input', function () {
		picking = null;
		renderGrid();
	});
	search.addEventListener('keydown', function (e) {
		// Entrée dans la recherche : ajoute le seul produit trouvé, sans valider la vente.
		if (e.key === 'Enter') {
			e.preventDefault();

			// Référence exacte (douchette) : le produit ou la variante va directement au panier.
			var code = (search.value || '').trim();
			var key = code ? codes[code.toLowerCase()] : null;

			if (key) {
				var it = items[key];

				search.value = '';
				picking = null;

				var before = cart[key] || 0;

				showError('');
				add(key, 1);

				// Rien ajouté : plus de stock pour cette référence (stock commun compris).
				if ((cart[key] || 0) === before) {
					showError(sprintf(t.scanOut || '%s', it.name));
				}

				return;
			}

			var tiles = grid.querySelectorAll('.passflow-pos-tile:not([disabled])');

			if (tiles.length === 1) {
				tiles[0].click();
			} else if (!grid.querySelector('.passflow-pos-tile') && code && code.indexOf(' ') === -1) {
				showError(sprintf(t.scanUnknown || '%s', code));
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
		var mode = methodSelect ? (methodSelect.value === 'link' ? 'link' : 'paid') : (e.submitter && e.submitter.value === 'link' ? 'link' : 'paid');

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
			expand(true);
			showError(t.needEmail || '');

			if (fields.email) {
				fields.email.focus();
			}

			return;
		}

		if (mode === 'link' && !(fields.phone && fields.phone.value.trim()) && !(fields.email && fields.email.value.trim())) {
			e.preventDefault();
			customer.open = true;
			expand(true);
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
				flashDone();
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

			syncSubmit();
		});
	});

	// ------------------------------------------------------------------
	// Gestion mobile PDA : encaissé du jour en tête, « Encaisser » puis un moyen de paiement
	// en un toucher (feuille du bas). Sur ordinateur, ces éléments restent cachés (pda.css).
	// ------------------------------------------------------------------

	var dayBox = root.querySelector('[data-pos-day]');
	var payBox = root.querySelector('[data-pos-paysheet]');
	var checkout = root.querySelector('[data-pos-checkout]');

	function fillDay() {
		var src = document.querySelector('[data-passflow-cashdesk-tab]');

		if (!dayBox || !src) {
			return;
		}

		dayBox.querySelector('[data-pos-day-total]').textContent = src.textContent;
		dayBox.hidden = !src.textContent.trim();
	}

	function paySheet(on) {
		if (!payBox) {
			return;
		}

		payBox.hidden = !on;

		if (!on) {
			return;
		}

		var total = 0;
		var parts = [];

		order.forEach(function (id) {
			total += items[id].price * cart[id];
			parts.push(cart[id] + ' × ' + items[id].name);
		});

		var name = ((fields.first ? fields.first.value : '') + ' ' + (fields.last ? fields.last.value : '')).trim();
		var contact = (fields.phone && fields.phone.value.trim()) || (fields.email && fields.email.value.trim()) || '';
		var sub = payBox.querySelector('[data-pos-pay-sub]');
		var who = payBox.querySelector('[data-pos-pay-who]');

		who.textContent = (who.getAttribute('data-label') || (who.setAttribute('data-label', who.textContent), who.textContent)) + (name ? ' · ' + name : '');
		payBox.querySelector('[data-pos-pay-total]').textContent = fmt.format(total);
		payBox.querySelector('[data-pos-pay-lines]').textContent = parts.join(' · ');

		if (sub) {
			sub.textContent = contact;
		}

		var main = payBox.querySelector('.is-main');

		if (main) {
			main.focus({ preventScroll: true });
		}
	}

	if (checkout) {
		checkout.addEventListener('click', function () {
			showError('');

			if (!order.length) {
				showError(t.pickOne || '');
				return;
			}

			expand(false);
			paySheet(true);
		});
	}

	if (payBox) {
		payBox.addEventListener('click', function (e) {
			var b = e.target.closest('[data-pos-pay]');

			if (e.target === payBox || e.target.closest('[data-pos-pay-back]')) {
				paySheet(false);
				return;
			}

			if (!b || busy) {
				return;
			}

			if (methodSelect) {
				methodSelect.value = b.getAttribute('data-pos-pay');
				syncSubmit();
			}

			paySheet(false);

			if (form.requestSubmit) {
				form.requestSubmit(submitButton);
			} else {
				submitButton.click();
			}
		});
	}

	// Vente validée : coche verte plein écran une seconde (PDA uniquement).
	function flashDone() {
		if (!document.documentElement.classList.contains('passflow-pda')) {
			return;
		}

		var flash = document.createElement('div');
		flash.className = 'passflow-pos-done';
		flash.setAttribute('aria-hidden', 'true');
		flash.innerHTML = '<svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg>';
		document.body.appendChild(flash);

		if (navigator.vibrate) {
			navigator.vibrate(60);
		}

		window.setTimeout(function () { flash.remove(); }, 1100);
	}

	// ------------------------------------------------------------------
	// Ajax commun : nouvelle vente, retour, recherche et pages de l'onglet Caisse.
	// ------------------------------------------------------------------

	var panel = document.querySelector('[data-passflow-cashdesk-panel]');
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
		body.set('hors_date', window.passflowForce ? '1' : '0');
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
				var focusQ = document.activeElement && document.activeElement.matches && document.activeElement.matches('[data-passflow-cashdesk-q]');
				var caret = focusQ ? document.activeElement.selectionStart : null;
				panel.innerHTML = d.panel;
				applyForce(panel);
				syncReturns(panel);

				if (focusQ) {
					var q = panel.querySelector('[data-passflow-cashdesk-q]');

					if (q) {
						q.focus();

						try { q.setSelectionRange(caret, caret); } catch (err) { /* type search */ }
					}
				}
			}

			if (d.tab !== undefined) {
				document.querySelectorAll('[data-passflow-cashdesk-tab]').forEach(function (n) { n.textContent = d.tab; });
			}

			if (d.bar !== undefined) {
				document.querySelectorAll('[data-passflow-cashdesk-bar]').forEach(function (n) { n.textContent = d.bar; });
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
		var sw = document.querySelector('[data-passflow-force-switch]');

		if (!sw) {
			return;
		}

		(scope || document).querySelectorAll('[data-passflow-lockable]').forEach(function (el) {
			el.disabled = !sw.checked;
		});
		(scope || document).querySelectorAll('[data-passflow-force]').forEach(function (el) {
			el.value = sw.checked ? '1' : '0';
		});
	}

	function updateUrl() {
		if (!window.history || !window.history.replaceState || !ajax.baseUrl) {
			return;
		}

		var url = new window.URL(ajax.baseUrl, window.location.href);
		var current = document.querySelector('[data-passflow-panel="caisse"]');

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
		var tabs = document.querySelectorAll('[data-passflow-tabs] [data-passflow-tab]');

		if (!tabs.length) {
			return false;
		}

		tabs.forEach(function (tab) {
			var on = tab.getAttribute('data-passflow-tab') === name;
			tab.classList.toggle('is-active', on);
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		document.querySelectorAll('[data-passflow-panel]').forEach(function (p) {
			p.hidden = p.getAttribute('data-passflow-panel') !== name;
		});
		updateUrl();
		window.scrollTo({ top: 0, behavior: 'smooth' });

		return true;
	}

	document.addEventListener('click', function (e) {
		var tab = e.target.closest('[data-passflow-tab]');

		if (tab && !e.metaKey && !e.ctrlKey && showTab(tab.getAttribute('data-passflow-tab'))) {
			e.preventDefault();
			return;
		}

		var pageLink = e.target.closest('[data-passflow-cashdesk-page]');

		if (pageLink && panel && ajax.action) {
			e.preventDefault();
			state.p = parseInt(pageLink.getAttribute('data-passflow-cashdesk-page'), 10) || 1;

			if (pageLink.hasAttribute('data-passflow-cashdesk-clear')) {
				state.q = '';
			}

			refreshPanel().then(function () {
				var top = panel.querySelector('.passflow-cashdesk-find');

				if (top && top.getBoundingClientRect().top < 0) {
					top.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			});
		}
	});

	// Recherche instantanée.
	var searchTimer = null;

	document.addEventListener('input', function (e) {
		if (!e.target.matches('[data-passflow-cashdesk-q]') || !ajax.action) {
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
		if (e.target.matches('[data-passflow-cashdesk-find]') && ajax.action) {
			e.preventDefault();
			var q = e.target.querySelector('[data-passflow-cashdesk-q]');
			state.q = q ? q.value.trim() : '';
			state.p = 1;
			refreshPanel();
		}
	});

	// Retour en bon d'achat : cases à cocher, quantité, confirmation maison, envoi Ajax.
	function syncReturn(f) {
		var acts = f.querySelector('[data-passflow-return-actions]');
		var n = 0;

		f.querySelectorAll('[data-passflow-return-line]').forEach(function (cb) {
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
		(scope || document).querySelectorAll('[data-passflow-return-form]').forEach(syncReturn);
	}

	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-passflow-return-line]')) {
			syncReturn(e.target.closest('form'));
		}
	});

	document.addEventListener('submit', function (e) {
		var f = e.target.closest('[data-passflow-return-form]');

		if (!f || e.target !== f) {
			return;
		}

		e.preventDefault();

		var lines = [];
		var total = 0;

		f.querySelectorAll('[data-passflow-return-line]:checked').forEach(function (cb) {
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
			+ '<ul class="passflow-ask-lines">' + lines.map(function (l) {
				return '<li><span>' + esc(l.name) + ' × ' + l.qty + '</span><strong>' + esc(fmt.format(l.amount)) + '</strong></li>';
			}).join('') + '</ul>'
			+ '<p class="passflow-ask-total">' + esc(sprintf(t.askTotal || '%s', fmt.format(total))) + '</p>'
			+ '<p class="passflow-muted">' + esc(sendBox && sendBox.checked ? sprintf(t.askSend || '%s', contact) : (t.askNoSend || '')) + ' ' + esc(t.askStock || '') + '</p>';

		ask(t.askTitle || '', html, t.askOk || 'OK').then(function (ok) {
			if (!ok) {
				return;
			}

			if (!ajax.action || !window.fetch) {
				f.submit();
				return;
			}

			var button = f.querySelector('[data-passflow-return-submit]');

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
		var link = e.target.closest('[data-passflow-cancel-order]');

		if (!link || !ajax.action || !window.fetch) {
			return;
		}

		e.preventDefault();
		var html = '<p>' + esc(sprintf2(t.askIntro || '%1$s, %2$s', link.getAttribute('data-client') || '', link.getAttribute('data-order') || '')) + ' · <strong>' + esc(link.getAttribute('data-total') || '') + '</strong></p>'
			+ '<p class="passflow-muted">' + esc(t.cancelText || '') + '</p>';

		ask(t.cancelTitle || '', html, t.cancelOk || 'OK', true).then(function (ok) {
			if (!ok) {
				return;
			}

			var body = new window.FormData();
			body.set('id', link.getAttribute('data-passflow-cancel-order'));
			link.classList.add('disabled');
			send('cancel', body).then(function (res) {
				toast(res.message, res.success ? 'success' : 'error');

				// Imprévu en attente (onglet Participants) : la ligne disparaît aussi.
				if (res.success && (!panel || !panel.contains(link))) {
					var row = link.closest('li');
					var box = row ? row.closest('.passflow-walkin-pending') : null;

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

	var askBox = document.querySelector('[data-passflow-ask]');
	var toasts = document.querySelector('[data-passflow-toasts]');

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
			document.documentElement.classList.add('passflow-pos-open');
			ok.focus();

			function done(value) {
				askBox.hidden = true;
				document.documentElement.classList.remove('passflow-pos-open');
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
		item.className = 'passflow-toast is-' + (kind || 'info');
		item.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		item.textContent = text;
		toasts.appendChild(item);
		window.setTimeout(function () { item.classList.add('is-out'); }, kind === 'error' ? 7000 : 4500);
		window.setTimeout(function () { item.remove(); }, kind === 'error' ? 7500 : 5000);
		item.addEventListener('click', function () { item.remove(); });
	}
}());
