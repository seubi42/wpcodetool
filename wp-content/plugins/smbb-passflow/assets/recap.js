/**
 * Passflow : récap animé d'une journée (façon « Wrapped »).
 * Données : <script type="application/json" id="passflow-recap-data"> rendu par Service\Recap::player().
 * Commandes : toucher à droite / à gauche, flèches, espace (pause), Échap (fermer), appui long = pause.
 */
(function () {
  'use strict';

  var root = document.getElementById('passflow-recap');
  var source = document.getElementById('passflow-recap-data');

  if (!root || !source) {
    return;
  }

  var payload;

  try {
    payload = JSON.parse(source.textContent);
  } catch (e) {
    return;
  }

  // Le lecteur sort de la page d'admin (.passflow-admin, #wpbody-content) : les thèmes du skin
  // CodeTool (Cristal, Porsche…) y restylent toutes les « -card », les titres et les liens.
  root.classList.add('wp-exclude-emoji');

  if (root.parentNode !== document.body) {
    document.body.appendChild(root);
  }

  var D = payload.data;
  var L = payload.links || {};
  var T = payload.i18n || {};
  var DURATION = 7000;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ------------------------------------------------------------------
  // Formats
  // ------------------------------------------------------------------

  var moneyFmt;
  var moneyFmt0;

  try {
    moneyFmt = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: D.currency || 'EUR' });
    moneyFmt0 = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: D.currency || 'EUR', maximumFractionDigits: 0 });
  } catch (e) {
    moneyFmt = moneyFmt0 = { format: function (n) { return Math.round(n) + ' €'; } };
  }

  function money(n, round) {
    return (round ? moneyFmt0 : moneyFmt).format(n || 0).replace(/ | /g, ' ');
  }

  function pct(x) {
    return Math.round((x || 0) * 100) + ' %';
  }

  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;

    return String(str || '').replace(/%(\d+\$)?[sd]/g, function () {
      return args[i++];
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function count(value, kind, extra) {
    return '<span class="rc-count" data-count="' + (value || 0) + '" data-kind="' + (kind || 'int') + '"' + (extra || '') + '>' + display(value, kind) + '</span>';
  }

  function display(value, kind) {
    if (kind === 'money') {
      return esc(money(value, value >= 1000));
    }

    if (kind === 'pct') {
      return Math.round(value) + '<small> %</small>';
    }

    if (kind === 'dec') {
      return (Math.round(value * 10) / 10).toFixed(1).replace('.', ',');
    }

    return String(Math.round(value));
  }


  // Icônes intégrées (traits, couleur du texte) : pas d'émojis, que WordPress remplace par des
  // images distantes (wp-emoji) qui ne chargent pas toujours.
  var ICONS = {
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    check: '<circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/>',
    money: '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
    star: '<path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5-4.9-4.6 6.6-.8z"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    phone: '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
    doc: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/>',
    trophy: '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>'
  };

  function icon(name, cls) {
    return '<span class="rc-ico' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[name] + '</svg></span>';
  }

  // ------------------------------------------------------------------
  // Cartes
  // ------------------------------------------------------------------

  var p = D.presence;
  var h = D.history;
  var m = D.money;
  var rv = D.reviews;
  var b = D.backstage;
  var cards = [];

  function card(theme, html, opts) {
    cards.push({ theme: theme, html: html, opts: opts || {} });
  }

  // 1. Ouverture
  card('intro',
    (D.logo ? '<img class="rc-logo rc-in" src="' + esc(D.logo) + '" alt="">' : '<p class="rc-site rc-in">' + esc(D.site) + '</p>')
    + '<p class="rc-kicker rc-in">' + esc(T.kicker) + '</p>'
    + '<h2 class="rc-title rc-in">' + esc(D.activity) + '</h2>'
    + '<p class="rc-sub rc-in">' + esc(D.when) + '</p>'
    + (D.hosts && D.hosts.length ? '<p class="rc-sub rc-in">' + esc(fmt(T['with'], D.hosts.join(', '))) + '</p>' : '')
    + (D.cancelled ? '<p class="rc-pill rc-in">' + esc(T.cancelled) + '</p>' : (!D.past ? '<p class="rc-pill rc-in">' + esc(T.provisional) + '</p>' : ''))
    + '<p class="rc-big-q rc-in">' + esc(T.intro) + '</p>'
    + '<p class="rc-hint rc-in">' + esc(T.introHint) + '</p>',
    { shapes: true }
  );

  // 2. Présence
  if (p.booked > 0) {
    var rateVal = p.rate != null ? p.rate * 100 : 0;
    var C = 2 * Math.PI * 54;

    card('presence',
      '<p class="rc-kicker rc-in">' + esc(T.presentTitle) + '</p>'
      + '<p class="rc-hero rc-in">' + count(p.present) + '</p>'
      + '<p class="rc-sub rc-in">' + esc(T.present) + ' · ' + esc(fmt(T.onBooked, p.booked)) + '</p>'
      + '<div class="rc-rings rc-in">'
      + '<figure class="rc-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="rc-ring-bg" cx="60" cy="60" r="54"/><circle class="rc-ring-fg" cx="60" cy="60" r="54" style="stroke-dasharray:' + C + ';stroke-dashoffset:' + C + '" data-dash="' + (C * (1 - Math.min(1, rateVal / 100))) + '"/></svg>'
      + '<figcaption><strong>' + count(rateVal, 'pct') + '</strong><span>' + esc(T.presence) + '</span></figcaption></figure>'
      + (p.fill != null ? '<figure class="rc-ring is-alt"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="rc-ring-bg" cx="60" cy="60" r="54"/><circle class="rc-ring-fg" cx="60" cy="60" r="54" style="stroke-dasharray:' + C + ';stroke-dashoffset:' + C + '" data-dash="' + (C * (1 - p.fill)) + '"/></svg>'
        + '<figcaption><strong>' + count(p.fill * 100, 'pct') + '</strong><span>' + esc(T.fill) + '</span></figcaption></figure>' : '')
      + '</div>'
      + (p.walkin > 0 ? '<p class="rc-pill rc-in">' + esc(fmt(T.walkin, p.walkin)) + '</p>' : '')
      + (p.present === 0 && D.past ? '<p class="rc-sub rc-in">' + esc(T.nobody) + '</p>' : ''),
      { confetti: p.rate === 1 && D.past }
    );
  }

  // 3. Tendance (dates précédentes du même atelier)
  if (h.count > 0 && p.booked > 0) {
    var rows = [];
    var record = false;
    var trend = function (label, value, avg, best, kind) {
      if (value == null || avg == null) {
        return;
      }

      var diff;
      var txt;

      if (kind === 'pct') {
        diff = Math.round((value - avg) * 100);
        txt = (diff > 0 ? '+' : diff < 0 ? '−' : '') + fmt(T.pts, Math.abs(diff));
      } else if (kind === 'money') {
        diff = avg > 0 ? Math.round((value - avg) * 100 / avg) : 0;
        txt = (diff > 0 ? '+' : diff < 0 ? '−' : '') + Math.abs(diff) + ' %';
      } else {
        diff = Math.round(value - avg);
        txt = (diff > 0 ? '+' : diff < 0 ? '−' : '') + Math.abs(diff);
      }

      record = record || best;
      rows.push('<li class="rc-trend rc-in ' + (diff > 0 ? 'is-up' : diff < 0 ? 'is-down' : 'is-flat') + '">'
        + '<span class="rc-trend-label">' + esc(label) + '</span>'
        + '<span class="rc-trend-value">' + (kind === 'pct' ? pct(value) : kind === 'money' ? esc(money(value, true)) : Math.round(value)) + '</span>'
        + '<span class="rc-trend-delta">' + (best ? '<b class="rc-record">★ ' + esc(T.record) + '</b>' : diff === 0 ? esc(T.stable) : '<i aria-hidden="true">' + (diff > 0 ? '↗' : '↘') + '</i> ' + esc(txt)) + '</span>'
        + '</li>');
    };

    trend(T.presentShort, p.present, h.present_avg, h.best_present, 'int');
    trend(T.rate, p.rate, h.rate_avg, h.best_rate, 'pct');
    trend(T.fillShort, p.fill, h.fill_avg, h.best_fill, 'pct');

    if (m) {
      trend(T.revenue, m.total, h.revenue_avg, h.best_revenue, 'money');
    }

    if (rows.length) {
      card('trend',
        '<p class="rc-kicker rc-in">' + esc(T.trendTitle) + '</p>'
        + '<p class="rc-sub rc-in">' + esc(h.count > 1 ? fmt(T.trendSub, h.count) : T.trendOne) + '</p>'
        + '<ul class="rc-trends">' + rows.join('') + '</ul>',
        { confetti: record && D.past }
      );
    }
  }

  // 4. Chiffre d'affaires
  if (m && m.total > 0) {
    var share = m.total > 0 ? m.bookings / m.total : 1;

    card('money',
      '<p class="rc-kicker rc-in">' + esc(T.moneyTitle) + '</p>'
      + '<p class="rc-hero is-money rc-in">' + count(m.total, 'money') + '</p>'
      + (m.per_head ? '<p class="rc-sub rc-in">' + esc(fmt(T.perHead, money(m.per_head))) + '</p>' : '')
      + (m.onsite > 0 ? '<div class="rc-split rc-in"><span class="is-a" data-w="' + (share * 100) + '"></span><span class="is-b" data-w="' + ((1 - share) * 100) + '"></span></div>'
        + '<ul class="rc-legend rc-in"><li><i class="is-a"></i>' + esc(T.bookings) + ' <strong>' + esc(money(m.bookings, true)) + '</strong></li>'
        + '<li><i class="is-b"></i>' + esc(T.onsite) + ' <strong>' + esc(money(m.onsite, true)) + '</strong></li></ul>'
        + '<p class="rc-pill rc-in">' + esc(fmt(T.salesCount, m.sales)) + '</p>' : ''),
      { coins: true }
    );
  }

  // 5. Meilleure vente
  if (D.products && D.products.length) {
    var top = D.products[0];
    var podium = D.products.slice(1).map(function (x, i) {
      return '<li class="rc-in"><span class="rc-rank">' + (i + 2) + '</span><span class="rc-name">' + esc(x.name) + '</span><span class="rc-qty">' + esc(fmt(T.sold, x.qty)) + '</span></li>';
    }).join('');

    card('top',
      '<p class="rc-kicker rc-in">' + esc(T.topTitle) + '</p>'
      + '<div class="rc-trophy rc-in">' + icon('trophy') + '</div>'
      + '<h2 class="rc-title rc-in">' + esc(top.name) + '</h2>'
      + '<p class="rc-hero is-small rc-in">' + count(top.qty) + '<small> ' + esc(fmt(T.sold, '').replace(/^\s*/, '').trim()) + '</small></p>'
      + (top.total != null ? '<p class="rc-sub rc-in">' + esc(money(top.total)) + '</p>' : '')
      + (podium ? '<ol class="rc-podium">' + podium + '</ol>' : '')
    );
  }

  // 6. Avis
  if (rv.answered > 0) {
    var avg = rv.average;
    var quotes = (rv.quotes || []).map(function (q, i) {
      return '<blockquote class="rc-quote rc-in" style="--i:' + i + '">“' + esc(q) + '”</blockquote>';
    }).join('');

    card('reviews',
      '<p class="rc-kicker rc-in">' + esc(avg != null && avg >= 4 ? T.reviewsTitle : T.reviewsMixed) + '</p>'
      + (avg != null ? '<p class="rc-hero rc-in">' + count(avg, 'dec') + '<small> ' + esc(T.onFive) + '</small></p>'
        + '<p class="rc-stars rc-in" aria-hidden="true"><span>★★★★★</span><span class="rc-stars-on" data-w="' + (avg * 20) + '">★★★★★</span></p>' : '')
      + '<p class="rc-sub rc-in">' + esc(fmt(T.answers, rv.answered)) + '</p>'
      + quotes,
      { confetti: avg != null && avg >= 4.5 }
    );
  }

  // 7. Coulisses
  if (b.sms + b.email > 0 || b.docs != null) {
    card('backstage',
      '<p class="rc-kicker rc-in">' + esc(T.backstageTitle) + '</p>'
      + '<ul class="rc-tiles">'
      + (b.email ? '<li class="rc-in">' + icon('mail') + '<strong>' + count(b.email) + '</strong><span>' + esc(T.emails) + '</span></li>' : '')
      + (b.sms ? '<li class="rc-in">' + icon('phone') + '<strong>' + count(b.sms) + '</strong><span>' + esc(T.sms) + '</span></li>' : '')
      + (b.docs != null ? '<li class="rc-in">' + icon('doc') + '<strong>' + count(b.docs * 100, 'pct') + '</strong><span>' + esc(T.docs) + '</span></li>' : '')
      + '</ul>'
      + '<p class="rc-big-q is-small rc-in">' + esc(T.auto) + '</p>'
    );
  }

  // 8. Fin : la synthèse + les actions
  var summary = [];

  if (p.booked > 0) {
    summary.push(['users', p.present, T.present]);
    summary.push(['check', pct(p.rate), T.presence]);
  }

  if (m && m.total > 0) {
    summary.push(['money', money(m.total, true), T.revenue]);
  }

  if (rv.average != null) {
    summary.push(['star', String(rv.average.toFixed(1)).replace('.', ',') + ' ' + T.onFive, fmt(T.answers, rv.answered)]);
  }

  card('outro',
    '<p class="rc-kicker rc-in">' + esc(D.activity) + ' · ' + esc(D.when) + '</p>'
    + '<h2 class="rc-title rc-in">' + esc(T.outroTitle) + '</h2>'
    + (summary.length ? '<ul class="rc-summary">' + summary.map(function (s) {
      return '<li class="rc-in">' + icon(s[0]) + '<strong>' + esc(s[1]) + '</strong><small>' + esc(s[2]) + '</small></li>';
    }).join('') + '</ul>' : '')
    + '<div class="rc-actions rc-in">'
    + '<button type="button" class="rc-btn is-primary" data-act="share">' + esc(T.share) + '</button>'
    + '<button type="button" class="rc-btn" data-act="image">' + esc(T.image) + '</button>'
    + '<button type="button" class="rc-btn is-ghost" data-act="replay">↻ ' + esc(T.replay) + '</button>'
    + (L.close ? '<a class="rc-btn is-ghost" href="' + esc(L.close) + '">' + esc(T.back) + '</a>' : '')
    + '</div>'
    + '<div class="rc-share" hidden>'
    + '<p><strong>' + esc(T.shareTitle) + '</strong><br><small>' + esc(T.shareText) + '</small></p>'
    + (L.share_money ? '<label class="rc-check"><input type="checkbox" data-act="money"> ' + esc(T.withMoney) + '</label>' : '')
    + '<input type="text" class="rc-url" readonly value="' + esc(L.share || location.href) + '">'
    + '<div class="rc-actions"><button type="button" class="rc-btn is-primary" data-act="copy">' + esc(T.copy) + '</button>'
    + '<a class="rc-btn is-ghost" data-act="open" target="_blank" rel="noopener" href="' + esc(L.share || location.href) + '">' + esc(T.openLink) + '</a></div>'
    + '</div>',
    { final: true, confetti: true }
  );

  // ------------------------------------------------------------------
  // Lecteur
  // ------------------------------------------------------------------

  root.innerHTML = ''
    + '<div class="rc-stage" tabindex="-1">'
    + '<div class="rc-bars">' + cards.map(function () { return '<span><i></i></span>'; }).join('') + '</div>'
    + '<div class="rc-top"><span class="rc-brand">' + esc(D.site) + '</span>'
    + '<button type="button" class="rc-icon" data-act="pause" aria-label="' + esc(T.pause) + '"><span class="rc-pause-ico" aria-hidden="true"></span></button>'
    + (L.close ? '<a class="rc-icon" href="' + esc(L.close) + '" aria-label="' + esc(T.close) + '">✕</a>' : '')
    + '</div>'
    + cards.map(function (c, i) {
      return '<section class="rc-card is-' + c.theme + '" data-index="' + i + '" aria-hidden="true">'
        + '<div class="rc-bg" aria-hidden="true"><i></i><i></i><i></i></div>'
        + '<div class="rc-body">' + c.html + '</div></section>';
    }).join('')
    + '<button type="button" class="rc-nav is-prev" data-act="prev" aria-label="' + esc(T.prev) + '"></button>'
    + '<button type="button" class="rc-nav is-next" data-act="next" aria-label="' + esc(T.next) + '"></button>'
    + '<canvas class="rc-confetti" aria-hidden="true"></canvas>'
    + '</div>';

  document.documentElement.classList.add('passflow-recap-open');

  var stage = root.querySelector('.rc-stage');
  var sections = root.querySelectorAll('.rc-card');
  var bars = root.querySelectorAll('.rc-bars > span');

  // Entrées en cascade : chaque élément animé reçoit son rang dans la carte.
  Array.prototype.forEach.call(sections, function (s) {
    Array.prototype.forEach.call(s.querySelectorAll('.rc-in'), function (el, i) {
      el.style.setProperty('--d', i);
    });
  });
  var current = -1;
  var paused = false;
  var started = 0;
  var elapsed = 0;
  var raf = 0;

  function setPaused(value) {
    paused = value;
    root.classList.toggle('is-paused', paused);
    root.querySelector('[data-act="pause"]').setAttribute('aria-label', paused ? T.play : T.pause);

    if (!paused) {
      started = performance.now() - elapsed;
    }
  }

  function go(index) {
    if (index < 0) {
      index = 0;
    }

    if (index >= cards.length) {
      return;
    }

    var previous = current;
    current = index;
    elapsed = 0;
    started = performance.now();

    Array.prototype.forEach.call(sections, function (s, i) {
      s.classList.toggle('is-active', i === index);
      s.classList.toggle('is-before', i < index);
      s.setAttribute('aria-hidden', i === index ? 'false' : 'true');

      if (i !== index) {
        s.classList.remove('is-played');
      }
    });

    Array.prototype.forEach.call(bars, function (bar, i) {
      bar.firstChild.style.transform = 'scaleX(' + (i < index ? 1 : 0) + ')';
    });

    var section = sections[index];

    // Relance les animations d'entrée de la carte.
    void section.offsetWidth;
    section.classList.add('is-played');
    animate(section);

    if (cards[index].opts.confetti && previous !== index) {
      confetti(cards[index].opts.final ? 160 : 110);
    }

    if (cards[index].opts.coins && previous !== index) {
      confetti(70, true);
    }

    root.classList.toggle('is-final', !!cards[index].opts.final);
  }

  function next() {
    if (current < cards.length - 1) {
      go(current + 1);
    }
  }

  function prev() {
    go(current - 1);
  }

  function tick(now) {
    raf = requestAnimationFrame(tick);

    if (paused || current < 0 || cards[current].opts.final) {
      return;
    }

    elapsed = now - started;
    var ratio = Math.min(1, elapsed / DURATION);
    bars[current].firstChild.style.transform = 'scaleX(' + ratio + ')';

    if (ratio >= 1) {
      next();
    }
  }

  // Compteurs, anneaux, barres.
  function animate(section) {
    Array.prototype.forEach.call(section.querySelectorAll('[data-count]'), function (el) {
      var target = parseFloat(el.getAttribute('data-count')) || 0;
      var kind = el.getAttribute('data-kind');

      if (reduced) {
        el.innerHTML = display(target, kind);
        return;
      }

      var t0 = performance.now() + 350;
      var dur = 1400;

      el.innerHTML = display(0, kind);

      (function step(now) {
        var k = Math.max(0, Math.min(1, (now - t0) / dur));
        var eased = 1 - Math.pow(1 - k, 4);
        el.innerHTML = display(kind === 'dec' ? target * eased : (kind === 'money' ? target * eased : Math.round(target * eased)), kind);

        if (k < 1 && section.classList.contains('is-active')) {
          requestAnimationFrame(step);
        } else {
          el.innerHTML = display(target, kind);
        }
      })(performance.now());
    });

    Array.prototype.forEach.call(section.querySelectorAll('.rc-ring-fg'), function (el) {
      el.style.transition = 'none';
      el.style.strokeDashoffset = el.style.strokeDasharray;
      void el.getBoundingClientRect();
      el.style.transition = '';
      setTimeout(function () {
        el.style.strokeDashoffset = el.getAttribute('data-dash');
      }, reduced ? 0 : 300);
    });

    Array.prototype.forEach.call(section.querySelectorAll('[data-w]'), function (el) {
      el.style.transition = 'none';
      el.style.width = '0';
      void el.offsetWidth;
      el.style.transition = '';
      setTimeout(function () {
        el.style.width = el.getAttribute('data-w') + '%';
      }, reduced ? 0 : 450);
    });
  }

  // ------------------------------------------------------------------
  // Confettis (canvas maison, rien à charger)
  // ------------------------------------------------------------------

  var canvas = root.querySelector('.rc-confetti');
  var ctx = canvas.getContext('2d');
  var pieces = [];
  var confettiRaf = 0;

  function confetti(n, coins) {
    if (reduced) {
      return;
    }

    var rect = stage.getBoundingClientRect();
    var ratio = window.devicePixelRatio || 1;
    canvas.width = rect.width * ratio;
    canvas.height = rect.height * ratio;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

    var colors = coins ? ['#ffd166', '#f4b400', '#ffe08a', '#fff3c4'] : ['#ffd166', '#06d6a0', '#ef476f', '#4cc9f0', '#ffffff', D.brand || '#8c3c34'];

    for (var i = 0; i < n; i++) {
      pieces.push({
        x: coins ? Math.random() * rect.width : rect.width / 2 + (Math.random() - 0.5) * 80,
        y: coins ? -20 - Math.random() * rect.height * 0.5 : rect.height * 0.35,
        vx: coins ? (Math.random() - 0.5) * 1.5 : (Math.random() - 0.5) * 12,
        vy: coins ? 2 + Math.random() * 3 : -6 - Math.random() * 9,
        r: coins ? 6 + Math.random() * 6 : 4 + Math.random() * 5,
        a: Math.random() * Math.PI,
        va: (Math.random() - 0.5) * 0.3,
        c: colors[i % colors.length],
        coin: !!coins,
        life: 0
      });
    }

    if (!confettiRaf) {
      confettiRaf = requestAnimationFrame(drawConfetti);
    }
  }

  function drawConfetti() {
    var w = canvas.width / (window.devicePixelRatio || 1);
    var hgt = canvas.height / (window.devicePixelRatio || 1);
    ctx.clearRect(0, 0, w, hgt);

    pieces = pieces.filter(function (q) {
      return q.y < hgt + 30 && q.life < 400;
    });

    pieces.forEach(function (q) {
      q.life++;
      q.vy += q.coin ? 0.05 : 0.25;
      q.vx *= 0.99;
      q.x += q.vx;
      q.y += q.vy;
      q.a += q.va;
      ctx.save();
      ctx.translate(q.x, q.y);
      ctx.rotate(q.a);
      ctx.fillStyle = q.c;

      if (q.coin) {
        ctx.scale(Math.abs(Math.cos(q.a * 2)) + 0.15, 1);
        ctx.beginPath();
        ctx.arc(0, 0, q.r, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = 'rgba(0,0,0,.15)';
        ctx.beginPath();
        ctx.arc(0, 0, q.r * 0.6, 0, Math.PI * 2);
        ctx.fill();
      } else {
        ctx.fillRect(-q.r / 2, -q.r / 4, q.r, q.r / 2);
      }

      ctx.restore();
    });

    confettiRaf = pieces.length ? requestAnimationFrame(drawConfetti) : 0;
  }

  // ------------------------------------------------------------------
  // Image à partager (format story 1080 × 1920)
  // ------------------------------------------------------------------

  function wrap(c, text, x, y, maxW, lh, maxLines) {
    var words = String(text).split(/\s+/);
    var line = '';
    var lines = [];

    words.forEach(function (w) {
      var test = line ? line + ' ' + w : w;

      if (c.measureText(test).width > maxW && line) {
        lines.push(line);
        line = w;
      } else {
        line = test;
      }
    });

    if (line) {
      lines.push(line);
    }

    if (maxLines && lines.length > maxLines) {
      lines = lines.slice(0, maxLines);
      lines[maxLines - 1] = lines[maxLines - 1].replace(/\s*\S*$/, '') + '…';
    }

    lines.forEach(function (l, i) {
      c.fillText(l, x, y + i * lh);
    });

    return y + lines.length * lh;
  }

  function roundRect(c, x, y, w, hh, r) {
    c.beginPath();
    c.moveTo(x + r, y);
    c.arcTo(x + w, y, x + w, y + hh, r);
    c.arcTo(x + w, y + hh, x, y + hh, r);
    c.arcTo(x, y + hh, x, y, r);
    c.arcTo(x, y, x + w, y, r);
    c.closePath();
  }

  function buildImage(done) {
    var W = 1080;
    var H = 1920;
    var cv = document.createElement('canvas');
    cv.width = W;
    cv.height = H;
    var c = cv.getContext('2d');
    var font = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    var brand = D.brand || '#8c3c34';

    var g = c.createLinearGradient(0, 0, W, H);
    g.addColorStop(0, brand);
    g.addColorStop(1, '#14101a');
    c.fillStyle = g;
    c.fillRect(0, 0, W, H);

    // Formes décoratives.
    c.globalAlpha = 0.18;
    c.fillStyle = '#ffd166';
    c.beginPath();
    c.arc(W - 120, 260, 260, 0, Math.PI * 2);
    c.fill();
    c.fillStyle = '#4cc9f0';
    c.beginPath();
    c.arc(80, H - 300, 320, 0, Math.PI * 2);
    c.fill();
    c.globalAlpha = 1;

    c.fillStyle = '#fff';
    c.textBaseline = 'top';
    c.font = '600 40px ' + font;
    c.globalAlpha = 0.8;
    c.fillText(String(D.site || '').toUpperCase(), 90, 120);
    c.globalAlpha = 1;
    c.font = '800 96px ' + font;
    var y = wrap(c, D.activity, 90, 220, W - 180, 108, 3);
    c.font = '500 44px ' + font;
    c.globalAlpha = 0.85;
    y = wrap(c, D.when, 90, y + 24, W - 180, 56, 2);
    c.globalAlpha = 1;

    // Tuiles 2 × 2.
    var tiles = [];

    if (p.booked > 0) {
      tiles.push([String(p.present), T.present]);
      tiles.push([pct(p.rate).replace(' ', ' '), T.presence]);
    }

    if (m && m.total > 0) {
      tiles.push([money(m.total, true).replace(/ /g, ' '), T.revenue]);
    }

    if (rv.average != null) {
      tiles.push([String(rv.average.toFixed(1)).replace('.', ',') + ' ★', fmt(T.answers, rv.answered)]);
    } else if (p.fill != null) {
      tiles.push([pct(p.fill).replace(' ', ' '), T.fill]);
    }

    var top = Math.max(y + 90, 760);
    var tw = (W - 180 - 40) / 2;
    var th = 300;

    tiles.slice(0, 4).forEach(function (t, i) {
      var x = 90 + (i % 2) * (tw + 40);
      var ty = top + Math.floor(i / 2) * (th + 40);
      c.fillStyle = 'rgba(255,255,255,.12)';
      roundRect(c, x, ty, tw, th, 36);
      c.fill();
      c.fillStyle = '#fff';
      c.font = '800 92px ' + font;
      c.fillText(t[0], x + 44, ty + 60, tw - 88);
      c.font = '500 36px ' + font;
      c.globalAlpha = 0.8;
      wrap(c, t[1], x + 44, ty + 190, tw - 88, 44, 2);
      c.globalAlpha = 1;
    });

    var by = top + Math.ceil(Math.min(4, tiles.length) / 2) * (th + 40) + 30;

    if (D.products && D.products.length) {
      c.font = '600 36px ' + font;
      c.globalAlpha = 0.8;
      c.fillText('🏆 ' + T.topTitle, 90, by);
      c.globalAlpha = 1;
      c.font = '700 52px ' + font;
      by = wrap(c, D.products[0].name + ' · ' + fmt(T.sold, D.products[0].qty), 90, by + 54, W - 180, 62, 2) + 40;
    }

    if (rv.quotes && rv.quotes.length && by < H - 360) {
      c.font = 'italic 500 46px ' + font;
      wrap(c, '“' + rv.quotes[0] + '”', 90, by, W - 180, 60, 4);
    }

    c.font = '600 34px ' + font;
    c.globalAlpha = 0.7;
    c.fillText(T.kicker + ' · Passflow', 90, H - 130);
    c.globalAlpha = 1;

    var finish = function () {
      cv.toBlob(function (blob) {
        done(blob);
      }, 'image/png');
    };

    if (!D.logo) {
      finish();
      return;
    }

    var img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = function () {
      try {
        var lh = 120;
        var lw = img.width * lh / img.height;
        c.drawImage(img, W - 90 - Math.min(lw, 360), 90, Math.min(lw, 360), lh * Math.min(lw, 360) / lw);
        cv.toBlob(function () {}, 'image/png'); // lève une erreur si l'image « salit » le canvas.
      } catch (e) {
        // Logo d'un autre domaine : on s'en passe.
      }
      finish();
    };
    img.onerror = finish;
    img.src = D.logo;
  }

  function downloadImage() {
    buildImage(function (blob) {
      if (!blob) {
        return;
      }

      var file;

      try {
        file = new File([blob], T.imageName || 'recap.png', { type: 'image/png' });
      } catch (e) {
        file = null;
      }

      // Sur téléphone : la feuille de partage (Instagram, WhatsApp…). Sinon un téléchargement.
      if (file && navigator.canShare && navigator.canShare({ files: [file] }) && /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent)) {
        navigator.share({ files: [file], title: D.activity }).catch(function () {});
        return;
      }

      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = T.imageName || 'recap.png';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () {
        URL.revokeObjectURL(a.href);
        a.remove();
      }, 1000);
    });
  }

  // ------------------------------------------------------------------
  // Partage du lien
  // ------------------------------------------------------------------

  function shareUrl() {
    var box = root.querySelector('[data-act="money"]');

    return box && box.checked ? L.share_money : (L.share || location.href);
  }

  function openShare() {
    var panel = root.querySelector('.rc-share');

    // Public, sur téléphone : directement la feuille de partage.
    if (!L.share && navigator.share) {
      navigator.share({ title: document.title, url: location.href }).catch(function () {});
      return;
    }

    panel.hidden = !panel.hidden;

    if (!panel.hidden) {
      var input = panel.querySelector('.rc-url');
      input.focus();
      input.select();
    }
  }

  function copyUrl(btn) {
    var url = shareUrl();
    var ok = function () {
      btn.textContent = T.copied;
      setTimeout(function () {
        btn.textContent = T.copy;
      }, 1800);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(url).then(ok, function () {});
    } else {
      var input = root.querySelector('.rc-url');
      input.select();

      try {
        document.execCommand('copy');
        ok();
      } catch (e) {}
    }
  }

  // ------------------------------------------------------------------
  // Événements
  // ------------------------------------------------------------------

  var holdTimer = 0;
  var held = false;

  root.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]');

    if (!el) {
      return;
    }

    var act = el.getAttribute('data-act');

    if (act === 'money') {
      var url = shareUrl();
      root.querySelector('.rc-url').value = url;
      root.querySelector('[data-act="open"]').href = url;
      return;
    }

    if (act !== 'open') {
      e.preventDefault();
    }

    if (act === 'next' || act === 'prev') {
      if (held) {
        held = false;
        return;
      }

      act === 'next' ? next() : prev();
    } else if (act === 'pause') {
      setPaused(!paused);
    } else if (act === 'replay') {
      root.querySelector('.rc-share').hidden = true;
      setPaused(false);
      go(0);
    } else if (act === 'share') {
      openShare();
    } else if (act === 'copy') {
      copyUrl(el);
    } else if (act === 'image') {
      downloadImage();
    }
  });

  // Appui long sur une carte = pause (comme les stories), relâcher = reprise.
  root.addEventListener('pointerdown', function (e) {
    if (!e.target.closest('.rc-nav')) {
      return;
    }

    held = false;
    holdTimer = setTimeout(function () {
      held = true;
      setPaused(true);
    }, 260);
  });

  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
    root.addEventListener(type, function () {
      clearTimeout(holdTimer);

      if (held) {
        setPaused(false);
      }
    });
  });

  // Glisser vers la gauche / la droite.
  var sx = null;

  root.addEventListener('touchstart', function (e) {
    sx = e.touches[0].clientX;
  }, { passive: true });

  root.addEventListener('touchend', function (e) {
    if (sx === null) {
      return;
    }

    var dx = e.changedTouches[0].clientX - sx;
    sx = null;

    if (Math.abs(dx) > 60) {
      held = true; // évite le « clic » qui suit
      dx < 0 ? next() : prev();
      setTimeout(function () { held = false; }, 50);
    }
  }, { passive: true });

  document.addEventListener('keydown', function (e) {
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
      return;
    }

    if (e.key === 'ArrowRight') {
      next();
    } else if (e.key === 'ArrowLeft') {
      prev();
    } else if (e.key === ' ') {
      e.preventDefault();
      setPaused(!paused);
    } else if (e.key === 'Escape' && L.close) {
      location.href = L.close;
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden && !paused) {
      setPaused(true);
    }
  });

  go(0);
  stage.focus({ preventScroll: true });
  raf = requestAnimationFrame(tick);
})();
