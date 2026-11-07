/**
 * Build My Club admin — shell + list-page behaviour. Target: public/bmc-admin.js,
 * loaded on every EasyAdmin page via DashboardController::configureAssets()->addJsFile().
 *
 * Progressive enhancement only: every link, filter and pager works as a plain
 * server round-trip without this file. It adds
 *   1. one-open-at-a-time nav groups (current page's group auto-opens)
 *   2. PINNED / RECENT quick lists (localStorage, per browser)
 *   3. ⌘K / Ctrl+K "Jump to page" palette
 *   4. filter-rail auto-submit + quick date picks + rail toggle
 *   5. row selection → selection bar → "select all N matching"
 *   6. flash → toast (2.5s hold, 300ms stepped fade)
 *   7. server clock tick (same skew logic as the current layout override)
 * Requires (load before this file): lucide UMD, public/assets/kit-compositor.js.
 *
 * DOM contract (see example-clubs.html):
 *   nav links      a.bmc-link[data-page][data-group][data-zone][data-icon]  (data-page = stable key, e.g. "clubs")
 *   current page   <main class="bmc-main" data-bmc-page="clubs">
 */
(function () {
    'use strict';

    var LS_PINS = 'bmc-admin-pins', LS_RECENT = 'bmc-admin-recent', LS_RECENT_OPEN = 'bmc-admin-recent-open';
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var read = function (k) { try { return JSON.parse(localStorage.getItem(k) || '[]'); } catch (e) { return []; } };
    var write = function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };

    // Lucide is the only icon system (DS rule). <i data-lucide="name"> → inline SVG.
    var icons = function () { if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } }); };
    icons();

    // Club badges / kits: <span class="bmc-sprite" data-kit='{…kit config…}'> → background-image.
    // Drawn as a background, not <img>, so a bad config never throws a broken-image error mid-render.
    if (typeof window.composeKitSvg === 'function') {
        $$('[data-kit]').forEach(function (el) {
            try {
                var svg = window.composeKitSvg(JSON.parse(el.getAttribute('data-kit')), 'badge', null, 2);
                el.style.backgroundImage = 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
            } catch (e) { /* leave the empty 30×32 slot */ }
        });
    }

    var main = $('.bmc-main');
    var current = main ? main.getAttribute('data-bmc-page') : null;

    // Every navigable page, read from the server-rendered nav — the menu config stays the single source of truth.
    var pages = $$('.bmc-group a.bmc-link[data-page]').map(function (a) {
        return {
            key: a.getAttribute('data-page'), href: a.getAttribute('href'),
            label: a.querySelector('span').textContent.trim(),
            group: a.getAttribute('data-group'), zone: a.getAttribute('data-zone'),
            icon: a.getAttribute('data-icon'), count: (a.querySelector('.bmc-count') || {}).textContent || ''
        };
    });
    var byKey = {}; pages.forEach(function (p) { byKey[p.key] = p; });

    // ── 1. accordion ────────────────────────────────────────────────────
    var heads = $$('.bmc-group__head');
    function openGroup(head) {
        heads.forEach(function (h) {
            var on = h === head;
            h.setAttribute('aria-expanded', on ? 'true' : 'false');
            document.getElementById(h.getAttribute('aria-controls')).hidden = !on;
        });
    }
    heads.forEach(function (h) {
        h.addEventListener('click', function () {
            openGroup(h.getAttribute('aria-expanded') === 'true' ? null : h);
        });
    });
    var cur = $('.bmc-group .bmc-link.is-current');
    if (cur) {
        var g = cur.closest('.bmc-group');
        openGroup($('.bmc-group__head', g));
        // keep the active row in view inside the scrolling nav (no scrollIntoView — it can scroll the page)
        var nav = $('.bmc-nav');
        if (nav && cur.offsetTop > nav.clientHeight - 40) nav.scrollTop = cur.offsetTop - nav.clientHeight / 2;
    }

    // ── 2. pinned / recent ──────────────────────────────────────────────
    var pins = read(LS_PINS).filter(function (k) { return byKey[k]; });
    var recent = read(LS_RECENT).filter(function (k) { return byKey[k]; });
    if (current && byKey[current]) {
        recent = [current].concat(recent.filter(function (k) { return k !== current; })).slice(0, 6);
        write(LS_RECENT, recent);
    }
    function quickLink(p) {
        var a = document.createElement('a');
        a.className = 'bmc-link' + (p.key === current ? ' is-current' : '');
        a.href = p.href;
        a.innerHTML = '<span></span>' + (p.count ? '<span class="bmc-count"></span>' : '');
        a.firstChild.textContent = p.label;
        if (p.count) a.lastChild.textContent = p.count;
        return a;
    }
    // RECENT collapses and starts closed: it fills itself as you browse, so it is the list
    // most likely to be in the way. Opening it is remembered, per browser, like the filter rail.
    var recentToggle = $('#bmc-recent-toggle'), recentItems = $('#bmc-recent-items');
    var recentOpen = false;
    try { recentOpen = localStorage.getItem(LS_RECENT_OPEN) === 'open'; } catch (e) {}

    function applyRecentOpen() {
        if (!recentToggle || !recentItems) return;
        recentToggle.setAttribute('aria-expanded', recentOpen ? 'true' : 'false');
        recentItems.hidden = !recentOpen;
    }
    if (recentToggle) {
        recentToggle.addEventListener('click', function () {
            recentOpen = !recentOpen;
            try { localStorage.setItem(LS_RECENT_OPEN, recentOpen ? 'open' : 'closed'); } catch (e) {}
            applyRecentOpen();
        });
    }

    function renderQuick() {
        [['#bmc-pinned', pins], ['#bmc-recent', recent.filter(function (k) { return pins.indexOf(k) < 0; }).slice(0, 4)]].forEach(function (pair) {
            var box = $(pair[0]); if (!box) return;
            var list = $('.bmc-quick__items', box);
            list.innerHTML = '';
            pair[1].forEach(function (k) { list.appendChild(quickLink(byKey[k])); });
            // Still hidden entirely while empty — a disclosure with nothing behind it is worse
            // than no disclosure.
            box.hidden = pair[1].length === 0;
        });
        applyRecentOpen();
        var pinBtn = $('.bmc-pin');
        if (pinBtn) {
            var on = pins.indexOf(current) >= 0;
            pinBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            pinBtn.title = on ? 'Unpin from sidebar' : 'Pin to sidebar';
        }
    }
    var pinBtn = $('.bmc-pin');
    if (pinBtn && current) {
        pinBtn.addEventListener('click', function () {
            pins = pins.indexOf(current) >= 0 ? pins.filter(function (k) { return k !== current; }) : pins.concat(current);
            write(LS_PINS, pins);
            renderQuick();
        });
    }
    renderQuick();

    // ── 3. jump-to-page palette ─────────────────────────────────────────
    var scrim = $('#bmc-palette'), input = $('#bmc-palette-q'), list = $('#bmc-palette-list'), empty = $('#bmc-palette-empty');
    var results = [], sel = 0;
    function draw() {
        var q = input.value.trim().toLowerCase();
        results = pages.filter(function (p) { return !q || (p.label + ' ' + p.group).toLowerCase().indexOf(q) >= 0; });
        sel = Math.min(sel, Math.max(0, results.length - 1));
        list.innerHTML = '';
        results.forEach(function (p, i) {
            var li = document.createElement('li');
            li.innerHTML = '<a class="bmc-palette__row" role="option"><span class="bmc-palette__icon"><i data-lucide="" width="16" height="16"></i></span><span class="bmc-palette__label"></span><span class="bmc-palette__path"></span></a>';
            var a = li.firstChild;
            a.href = p.href; a.id = 'bmc-opt-' + i;
            a.setAttribute('aria-selected', i === sel ? 'true' : 'false');
            a.firstChild.firstChild.setAttribute('data-lucide', p.icon);
            a.children[1].textContent = p.label;
            a.children[2].textContent = p.zone + ' › ' + p.group;
            list.appendChild(li);
        });
        icons();
        input.setAttribute('aria-activedescendant', results.length ? 'bmc-opt-' + sel : '');
        empty.hidden = results.length > 0;
        var row = list.children[sel];
        if (row) {
            if (row.offsetTop < list.scrollTop) list.scrollTop = row.offsetTop;
            else if (row.offsetTop + row.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = row.offsetTop + row.offsetHeight - list.clientHeight;
        }
    }
    function openPalette() { if (!scrim) return; scrim.hidden = false; input.value = ''; sel = 0; draw(); input.focus(); }
    function closePalette() { if (scrim) scrim.hidden = true; }
    $$('[data-bmc-open-palette]').forEach(function (b) { b.addEventListener('click', openPalette); });
    if (scrim) {
        scrim.addEventListener('click', function (e) { if (e.target === scrim) closePalette(); });
        input.addEventListener('input', function () { sel = 0; draw(); });
    }
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            return scrim && scrim.hidden ? openPalette() : closePalette();
        }
        if (!scrim || scrim.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); closePalette(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(results.length - 1, sel + 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); draw(); }
        else if (e.key === 'Enter' && results[sel]) { e.preventDefault(); window.location.href = results[sel].href; }
    });

    // ── 4. filter rail ──────────────────────────────────────────────────
    var rail = $('#bmc-rail'), railForm = $('#bmc-filters'), railBtn = $('[data-bmc-toggle-rail]');
    var RAIL_KEY = 'bmc-admin-rail';
    if (rail && railBtn) {
        var stored = localStorage.getItem(RAIL_KEY);
        rail.hidden = stored ? stored === 'closed' : window.innerWidth < 1180;
        var label = function () { $('span', railBtn).textContent = rail.hidden ? railBtn.getAttribute('data-closed-label') : 'HIDE FILTERS'; };
        label();
        railBtn.addEventListener('click', function () { rail.hidden = !rail.hidden; localStorage.setItem(RAIL_KEY, rail.hidden ? 'closed' : 'open'); label(); });
    }
    if (railForm) {
        var t;
        var submit = function () { clearTimeout(t); railForm.requestSubmit ? railForm.requestSubmit() : railForm.submit(); };
        railForm.addEventListener('change', function (e) { if (e.target.matches('input[type=checkbox], input[type=radio], input[type=date]')) submit(); });
        railForm.addEventListener('input', function (e) { if (e.target.matches('input[type=text], input[type=search]')) { clearTimeout(t); t = setTimeout(submit, 450); } });
        // Drop untouched facets, then empty params within the rest, so the query string
        // carries only what the operator actually set.
        //
        // THE FACET SWEEP IS THE LOAD-BEARING HALF, and it used to be missing. Every facet
        // renders a hidden `comparison` input — "=" for choices, "between" for ranges, "like"
        // for text — and a hidden input ALWAYS has a value, so the per-input rule below could
        // never remove one. Left in, it tells EasyAdmin the filter is applied with no value,
        // and ChoiceFilterType reads that as IS NULL (see its buildForm: an EQ with a null or
        // empty value becomes 'IS NULL'). On a non-nullable column — position, status, tier,
        // size — that matches no rows. So touching any one facet emptied the whole list, on
        // every redesigned list page, which is exactly what was reported for Players and
        // Investors.
        //
        // A checked box whose value is '' does not count as touched: that is the boolean
        // segment's ALL option, which means "no filter", not "filter on empty".
        railForm.addEventListener('submit', function () {
            $$('.bmc-facet', railForm).forEach(function (facet) {
                var ticked = $$('input[type=checkbox], input[type=radio]', facet)
                    .some(function (b) { return b.checked && b.value !== ''; });
                var typed = $$('input[type=text], input[type=search], input[type=date], input[type=number], select', facet)
                    .some(function (el) { return el.value; });

                if (!ticked && !typed) {
                    $$('input, select', facet).forEach(function (el) { el.disabled = true; });
                }
            });

            $$('input, select', railForm).forEach(function (el) {
                if (!el.value && el.type !== 'checkbox' && el.type !== 'radio') el.disabled = true;
            });
        });
        $$('[data-bmc-days]', railForm).forEach(function (b) {
            b.addEventListener('click', function () {
                var box = b.closest('.bmc-facet'), days = +b.getAttribute('data-bmc-days');
                var to = new Date(), from = new Date(Date.now() - days * 864e5), iso = function (d) { return d.toISOString().slice(0, 10); };
                $('[data-bmc-from]', box).value = iso(from); $('[data-bmc-to]', box).value = iso(to);
                submit();
            });
        });
    }

    // ── 5. selection ────────────────────────────────────────────────────
    var boxes = $$('input[name="batchActionEntityIds[]"]'), headBox = $('#bmc-select-page');
    var selbar = $('#bmc-selbar'), chips = $('#bmc-chips'), selAll = $('#bmc-selall'), allField = $('#bmc-all-matching');
    function syncSel() {
        var n = boxes.filter(function (b) { return b.checked; }).length, all = allField && allField.value === '1';
        var total = selbar ? +selbar.getAttribute('data-total') : 0;
        boxes.forEach(function (b) { b.closest('tr').classList.toggle('is-selected', b.checked); });
        if (headBox) {
            headBox.checked = n > 0 && n === boxes.length;
            var tile = headBox.nextElementSibling;
            tile.classList.toggle('is-on', headBox.checked);
            tile.classList.toggle('is-mixed', n > 0 && n < boxes.length);
        }
        if (selbar) {
            selbar.hidden = n === 0;
            var shown = all ? total : n, noun = selbar.getAttribute(shown === 1 ? 'data-noun-one' : 'data-noun-many');
            $('.bmc-selbar__n', selbar).textContent = shown.toLocaleString('en-GB') + ' ' + noun.toUpperCase() + ' SELECTED';
        }
        if (chips) chips.hidden = n > 0;
        if (selAll) selAll.hidden = !(n === boxes.length && n > 0 && total > n && !all);
    }
    boxes.forEach(function (b) { b.addEventListener('change', function () { if (allField) allField.value = '0'; syncSel(); }); });
    if (headBox) headBox.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = headBox.checked; }); if (allField) allField.value = '0'; syncSel(); });
    if (selAll) $('button', selAll).addEventListener('click', function () { allField.value = '1'; syncSel(); });
    var clearBtn = $('[data-bmc-clear-selection]');
    if (clearBtn) clearBtn.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); allField.value = '0'; syncSel(); });
    syncSel();

    // Batch submit: EasyAdmin's CSRF token is per action — 'ea-batch-action-<action>-<fqcn>' —
    // so one hidden field cannot serve several buttons in one form. Each button carries its own
    // in data-action-csrf-token and we copy the right one across on click, which is what
    // EasyAdmin's own app.js does when it synthesises a form.
    var tokenField = $('#bmc-batch-token'), nameField = $('#bmc-batch-name'), urlField = $('#bmc-batch-url');
    $$('[data-bmc-batch]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            var confirmText = b.getAttribute('data-bmc-confirm');
            if (confirmText && !window.confirm(confirmText)) { e.preventDefault(); return; }
            if (tokenField) tokenField.value = b.getAttribute('data-action-csrf-token') || '';
            if (nameField) nameField.value = b.getAttribute('data-action-name') || '';
            if (urlField) urlField.value = b.getAttribute('data-action-url') || '';
            // The form posts to the action's own URL, not the index.
            var form = b.closest('form');
            if (form && b.getAttribute('data-action-url')) form.action = b.getAttribute('data-action-url');
        });
    });

    // Row-level destructive actions outside the batch bar.
    $$('[data-bmc-confirm]:not([data-bmc-batch])').forEach(function (b) {
        b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-bmc-confirm'))) e.preventDefault(); });
    });

    // ── 6. toast ────────────────────────────────────────────────────────
    $$('.bmc-toast').forEach(function (el, i) {
        setTimeout(function () { el.classList.add('is-leaving'); setTimeout(function () { el.remove(); }, 300); }, 2500 + i * 400);
    });

    // ── 7. server clock ─────────────────────────────────────────────────
    var clock = $('#bmc-server-clock');
    if (clock) {
        var serverMs = Date.parse(clock.getAttribute('data-server-time'));
        if (!isNaN(serverMs)) {
            var skew = serverMs - Date.now(), val = $('.bmc-clock__value', clock), p = function (n) { return String(n).padStart(2, '0'); };
            var tick = function () { var d = new Date(Date.now() + skew); val.textContent = d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds()); };
            tick(); setInterval(tick, 1000);
        }
    }
}());
