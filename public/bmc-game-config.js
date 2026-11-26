/**
 * Game config calibration workspace — templates/admin/game_config.html.twig.
 * Plain ES5, deferred, runs after bmc-pages.js (which owns dirty tracking and the save bar).
 *
 *  - Moves each .cfg-section into its group (#gc-workspace[data-groups]) and builds the side nav.
 *  - Collapsible sections, remembered per browser.
 *  - Per field: a readable preview (£ / × / % / years), the CODE default (from the entity, not
 *    the help text, which could be stale), and a CHANGED tag when the value differs from it.
 *  - Search, plus filters: changed from default, warnings, unsaved.
 *  - Live checks on config alone (wage ladder ratio, min above max, invalid JSON), each linked
 *    to its field.
 *  - The save bar lists every unsaved change, saved → new.
 * Stored values are never changed by this file: it only reads inputs and adds display.
 */
(function () {
    'use strict';

    var root = document.getElementById('gc-workspace');
    var form = document.getElementById('game-config-form');
    if (!root || !form) return;

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var GROUPS = JSON.parse(root.getAttribute('data-groups') || '[]');
    var DEF = {};
    try { DEF = JSON.parse(($('#gc-defaults') || {}).textContent || '{}'); } catch (e) { DEF = {}; }
    var store = {
        get: function (k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
        set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    };

    // ── 1. regroup ─────────────────────────────────────────────────────────
    var sections = $$('.cfg-section', form);
    var byId = {};
    sections.forEach(function (s) { byId[s.getAttribute('data-section-id')] = s; });
    var placed = {};
    GROUPS.forEach(function (g) {
        var body = $('[data-gc-group="' + g.id + '"] .gc-group__body', root);
        if (!body) return;
        g.sections.forEach(function (id) {
            if (byId[id] && !placed[id]) { body.appendChild(byId[id]); placed[id] = g.id; }
        });
    });
    var otherBody = $('[data-gc-group="other"] .gc-group__body', root);
    sections.forEach(function (s) {
        var id = s.getAttribute('data-section-id');
        if (!placed[id] && otherBody) { otherBody.appendChild(s); placed[id] = 'other'; }
    });
    $$('.gc-group', root).forEach(function (g) { if (!$('.cfg-section', g)) g.setAttribute('data-gc-empty', '1'); });

    function titleOf(sec) { var t = $('.bmc-card__title', sec); return t ? t.textContent.trim() : sec.getAttribute('data-section-id'); }

    // ── 2. fields and units ───────────────────────────────────────────────
    function keyOf(name) {
        var m = /^(?:clientTuning|poolConfig)\[([^\]]+)\]$/.exec(name);
        return m ? m[1] : name;
    }
    function isTick(f) { return f.type === 'checkbox' || f.type === 'radio'; }
    var fields = $$('input[name], select[name], textarea[name]', form).filter(function (f) {
        return !/^(hidden|submit|button|reset|file)$/.test(f.type) && f.name !== '_token';
    });
    var units = [];       // {el, section, fields[], label, text}
    var unitByEl = new Map();
    fields.forEach(function (f) {
        var sec = f.closest('.cfg-section');
        if (!sec) return;
        var el = f.closest('.bmc-setting, .bmc-field-col, .bmc-check, .table-responsive') || f.closest('table') || f.parentElement;
        var u = unitByEl.get(el);
        if (!u) {
            var lab = $('.bmc-setting__label, label:not(.bmc-num), th', el);
            u = { el: el, section: sec, fields: [], label: lab ? lab.textContent.trim() : titleOf(sec) };
            unitByEl.set(el, u);
            units.push(u);
            el.classList.add('gc-unit');
        }
        u.fields.push(f);
        f._gcUnit = u;
    });
    units.forEach(function (u) {
        u.text = (titleOf(u.section) + ' ' + u.el.textContent + ' ' + u.fields.map(function (f) { return keyOf(f.name); }).join(' ')).toLowerCase();
    });

    function fieldLabel(f) {
        var u = f._gcUnit;
        var tr = f.closest('tr');
        if (tr && tr.closest('table')) {
            var cells = Array.prototype.slice.call(tr.children);
            var idx = cells.indexOf(f.closest('td, th'));
            var head = tr.closest('table').querySelectorAll('thead th')[idx];
            return titleOf(u.section) + ' › ' + (cells[0] ? cells[0].textContent.trim() : '') + (head ? ' ' + head.textContent.trim() : '');
        }
        var label = f.labels && f.labels[0] && !f.labels[0].classList.contains('bmc-num') ? f.labels[0].textContent.trim() : u.label;
        return label;
    }

    // ── 3. units of measure ───────────────────────────────────────────────
    function gbp(p) {
        var neg = p < 0, a = Math.abs(p);
        var s = a.toLocaleString('en-GB', { minimumFractionDigits: a < 1000 && a % 1 !== 0 ? 2 : 0, maximumFractionDigits: 2 });
        if (a < 100 && a % 1 === 0 && a !== 0) s = a.toFixed(2);
        return (neg ? '−£' : '£') + s;
    }
    function helpOf(u) { var h = $('.bmc-setting__help, .bmc-data', u.el); return h ? h.textContent : ''; }
    var FRACTION_KEY = /(Chance|Probability|Share|Fraction|Trigger|Recovery|Overshoot)/;
    var FRACTION_HELP = /\(0\s*[–-]\s*1\)|\(0\.0[^)]*1\.0\)|fraction|probability|0\.0 = never/i;
    function formatterFor(f) {
        if (f.tagName === 'TEXTAREA' || f.tagName === 'SELECT' || isTick(f)) return null;
        var k = keyOf(f.name), help = helpOf(f._gcUnit);
        if (k === 'contractValueRandMin' || k === 'contractValueRandMax') return function (v) { return gbp(v / 100) + ' / ability pt / wk'; };
        if (/Bp$/.test(k)) return function (v) { return '×' + (v / 10000).toFixed(2); };
        if (f.getAttribute('data-gc-scale') === '100') return function (v) { return gbp(v); };
        if (/MicroPence$/.test(k)) return function (v) { return gbp(v / 1e8); };
        if (/Pence$/.test(k)) return function (v) { return gbp(v / 100); };
        if (/Weeks$/.test(k)) return function (v) { return v >= 26 ? '≈ ' + +(v / 52).toFixed(1) + ' yr' : ''; };
        if (!/Percent|Pct/.test(k) && (FRACTION_KEY.test(k) || FRACTION_HELP.test(help))) {
            return function (v) { return v >= 0 && v <= 1 ? '= ' + +(v * 100).toFixed(2) + '%' : ''; };
        }
        return null;
    }

    // ── 4. code defaults ──────────────────────────────────────────────────
    function canon(v) {
        if (v === null || typeof v !== 'object') return v;
        if (Array.isArray(v)) return v.map(canon);
        var o = {};
        Object.keys(v).sort().forEach(function (k) { o[k] = canon(v[k]); });
        return o;
    }
    function hasDefault(f) {
        var n = f.name;
        if (/\[/.test(n) && !/^(clientTuning|poolConfig)\[[^\]]+\]$/.test(n)) return false;
        return Object.prototype.hasOwnProperty.call(DEF, n) || Object.prototype.hasOwnProperty.call(DEF, keyOf(n)) && !/^clientTuning\[/.test(n);
    }
    function defaultOf(f) {
        var d = Object.prototype.hasOwnProperty.call(DEF, f.name) ? DEF[f.name] : DEF[keyOf(f.name)];
        if (typeof d === 'number' && f.getAttribute('data-gc-scale') === '100') d = d / 100;
        return d;
    }
    function currentOf(f) {
        if (isTick(f)) return f.checked;
        var v = String(f.value).trim();
        if (f.tagName === 'TEXTAREA') { if (v === '') return null; try { return canon(JSON.parse(v)); } catch (e) { return { __invalid: v }; } }
        if (f.getAttribute('data-kind') === 'money') v = v.replace(/[^\d.-]/g, '');
        return v === '' ? null : (isFinite(v) ? parseFloat(v) : v);
    }
    function sameAsDefault(f) {
        var d = defaultOf(f), c = currentOf(f);
        if (typeof d === 'boolean' || isTick(f)) return !!d === !!c;
        if (typeof d === 'number' && typeof c === 'number') return Math.abs(d - c) < 1e-9;
        if (d !== null && typeof d === 'object') return JSON.stringify(canon(d)) === JSON.stringify(c);
        return String(d === null ? '' : d) === String(c === null ? '' : c);
    }
    function showDefault(f) {
        var d = defaultOf(f);
        if (typeof d === 'boolean') return d ? 'on' : 'off';
        if (d === null) return 'none';
        if (typeof d === 'object') { var j = JSON.stringify(d); return j.length > 60 ? j.slice(0, 57) + '…' : j; }
        var fm = formatterFor(f), p = fm && typeof d === 'number' ? fm(d) : '';
        return d + (p ? ' (' + p.replace(/^= /, '') + ')' : '');
    }

    // Cut a trailing "Default: …" (or "Default 8.") off help text: the value could be stale.
    function stripDefault(t) {
        t = t.replace(/\s*\(changed\)\s*$/, '');
        var re = /(?:Code default|Default)\b\s*:?/g, m, last = -1;
        while ((m = re.exec(t))) last = m.index;
        return last >= 0 && t.length - last <= 120 ? t.slice(0, last).replace(/\s+$/, '') : t;
    }

    // Per-unit meta line: preview · code default · CHANGED / warning tags.
    units.forEach(function (u) {
        if (u.el.matches('table, .table-responsive')) return;   // tables carry their own columns
        var f = u.fields[0];
        var meta = document.createElement('span');
        meta.className = 'gc-meta';
        u.preview = document.createElement('span'); u.preview.className = 'gc-preview';
        u.defEl = document.createElement('span'); u.defEl.className = 'gc-default';
        u.tag = document.createElement('span'); u.tag.className = 'gc-tag gc-tag--changed'; u.tag.textContent = 'CHANGED'; u.tag.hidden = true;
        u.warnTag = document.createElement('span'); u.warnTag.className = 'gc-tag gc-tag--warn'; u.warnTag.hidden = true;
        meta.appendChild(u.preview); meta.appendChild(u.defEl); meta.appendChild(u.tag); meta.appendChild(u.warnTag);
        var defaulted = u.fields.filter(hasDefault);
        if (defaulted.length) {
            u.defEl.textContent = 'Code default: ' + defaulted.map(showDefault).join(' – ');
            // The help text's own "Default: …" is replaced by the real one above.
            $$('.bmc-setting__help, .bmc-data', u.el).forEach(function (h) {
                h.textContent = stripDefault(h.textContent);
            });
        }
        var host = $('.bmc-setting__text', u.el) || u.el;
        if (u.el.matches('.bmc-check')) host = u.el.parentElement.insertBefore(document.createElement('div'), u.el.nextSibling);
        host.appendChild(meta);
        u.fmt = u.fields.map(formatterFor);
    });

    // JSON textareas: a readable table of the parsed value under the box.
    function jsonCell(v, pence) {
        if (typeof v === 'number') return pence ? gbp(v / 100) : String(v);
        if (Array.isArray(v)) return v.map(function (x) { return jsonCell(x, pence); }).join(' – ');
        if (v && typeof v === 'object') return Object.keys(v).map(function (k) { return k + ': ' + jsonCell(v[k], pence); }).join(', ');
        return String(v);
    }
    fields.filter(function (f) { return f.tagName === 'TEXTAREA'; }).forEach(function (f) {
        var box = document.createElement('div'); box.className = 'gc-json';
        f.parentElement.insertBefore(box, f.nextSibling);
        var pence = /Pence$/.test(keyOf(f.name));
        f._gcJson = function () {
            var v;
            if (String(f.value).trim() === '') { box.innerHTML = ''; return; }
            try { v = JSON.parse(f.value); } catch (e) { box.innerHTML = ''; var er = document.createElement('div'); er.className = 'gc-json__err'; er.textContent = 'Not valid JSON: ' + e.message; box.appendChild(er); return; }
            box.innerHTML = '';
            if (v === null || typeof v !== 'object') return;
            var keys = Array.isArray(v) ? v.map(function (_, i) { return i; }) : Object.keys(v);
            if (!keys.length || keys.length > 30) return;
            var table = document.createElement('table');
            keys.forEach(function (k) {
                var tr = document.createElement('tr');
                var th = document.createElement('th'); th.textContent = Array.isArray(v) ? 'Tier ' + (k + 1) : k;
                var td = document.createElement('td'); td.textContent = jsonCell(v[k], pence);
                tr.appendChild(th); tr.appendChild(td); table.appendChild(tr);
            });
            box.appendChild(table);
        };
        f._gcJson();
    });

    // ── 5. live checks ────────────────────────────────────────────────────
    function partnerOf(name) {
        if (/\[min\]$/.test(name)) return name.replace(/\[min\]$/, '[max]');
        if (/Min(\]?)$/.test(name)) return name.replace(/Min(\]?)$/, 'Max$1');
        return null;
    }
    var byName = {};
    fields.forEach(function (f) { if (!isTick(f)) byName[f.name] = f; });
    var checksLive = $('#gc-checks-live');

    function runChecks() {
        var items = [];
        units.forEach(function (u) { u.warn = []; });
        // a) wage ladder
        var ladder = $('#wage-ladder');
        if (ladder) {
            var rows = $$('[data-ladder-row]', ladder).map(function (tr) {
                var g = function (k) { var el = $('[data-ladder="' + k + '"]', tr); var v = el ? String(el.value).trim() : ''; return v === '' ? 0 : parseFloat(v); };
                return { from: g('from'), player: g('player'), staff: g('staff') };
            }).sort(function (a, b) { return a.from - b.from; });
            var prev = null, dips = 0;
            rows.forEach(function (r) { var q = r.player > 0 ? r.staff / r.player : null; if (q !== null && prev !== null && q < prev - 1e-9) dips++; if (q !== null) prev = q; });
            if (dips) {
                var lu = unitByEl.get(ladder.closest('.table-responsive')) || unitByEl.get(ladder);
                if (lu) lu.warn.push('ratio dips');
                items.push({ warn: true, text: 'Wage tiers: staff / player ratio falls at ' + dips + ' tier(s), so staff earn relatively less as ability rises.', goto: lu });
            }
        }
        // b) min above max
        Object.keys(byName).forEach(function (n) {
            var p = partnerOf(n); if (!p || !byName[p]) return;
            var a = currentOf(byName[n]), b = currentOf(byName[p]);
            if (typeof a === 'number' && typeof b === 'number' && a > b) {
                var u = byName[n]._gcUnit; u.warn.push('min above max');
                if (byName[p]._gcUnit !== u) byName[p]._gcUnit.warn.push('min above max');
                items.push({ warn: true, text: fieldLabel(byName[n]) + ': min (' + a + ') is above max (' + b + ').', goto: u });
            }
        });
        // c) JSON that will not parse
        fields.forEach(function (f) {
            if (f.tagName !== 'TEXTAREA' || String(f.value).trim() === '') return;
            try { JSON.parse(f.value); } catch (e) {
                f._gcUnit.warn.push('invalid JSON');
                items.push({ warn: true, text: fieldLabel(f) + ': not valid JSON, so it will not save.', goto: f._gcUnit });
            }
        });

        checksLive.innerHTML = '';
        if (!items.length) {
            var ok = document.createElement('div'); ok.className = 'gc-check gc-check--ok';
            ok.textContent = 'No problems found in the values on this page.';
            checksLive.appendChild(ok);
        }
        items.forEach(function (it) {
            var row = document.createElement('div'); row.className = 'gc-check gc-check--warn';
            var t = document.createElement('span'); var st = document.createElement('strong'); st.textContent = '⚠ '; t.appendChild(st); t.appendChild(document.createTextNode(it.text));
            row.appendChild(t);
            if (it.goto) { var a = document.createElement('a'); a.href = '#'; a.textContent = 'Go to field'; a.addEventListener('click', function (e) { e.preventDefault(); reveal(it.goto); }); row.appendChild(a); }
            checksLive.appendChild(row);
        });
        $('#gc-checks').classList.toggle('has-warn', items.length > 0);
        units.forEach(function (u) {
            u.el.classList.toggle('gc-has-warn', u.warn.length > 0);
            if (u.warnTag) { u.warnTag.hidden = !u.warn.length; u.warnTag.textContent = u.warn.length ? '⚠ ' + u.warn[0].toUpperCase() : ''; }
        });
    }

    // ── 6. per-unit state + badges ────────────────────────────────────────
    function savedOf(f) { var s = f.getAttribute('data-saved'); return s === null ? null : String(s).trim(); }
    function normOf(f) {
        if (isTick(f)) return f.checked ? '1' : '';
        var v = String(f.value).trim();
        return f.getAttribute('data-kind') === 'money' ? v.replace(/[^\d]/g, '') : v;
    }
    function isDirty(f) { var s = savedOf(f); if (s === null) return false; return normOf(f) !== (f.getAttribute('data-kind') === 'money' ? s.replace(/[^\d]/g, '') : s); }

    function refreshUnit(u) {
        u.changed = u.fields.some(function (f) { return hasDefault(f) && !sameAsDefault(f); });
        u.unsaved = u.fields.some(isDirty);
        if (u.tag) u.tag.hidden = !u.changed;
        if (u.preview) {
            u.preview.textContent = u.fields.map(function (f, i) {
                var fm = u.fmt[i]; if (!fm) return '';
                var v = currentOf(f); return typeof v === 'number' ? fm(v) : '';
            }).filter(Boolean).join(' – ');
        }
    }

    function badge(cls, n, title) { var b = document.createElement('span'); b.className = 'gc-badge gc-badge--' + cls; b.textContent = n; b.title = title; return b; }
    function badges(list) {
        var box = document.createElement('span'); box.className = 'gc-badges';
        var w = list.filter(function (u) { return u.warn && u.warn.length; }).length;
        var s = list.filter(function (u) { return u.unsaved; }).length;
        var c = list.filter(function (u) { return u.changed; }).length;
        if (w) box.appendChild(badge('warn', '⚠ ' + w, w + ' with a warning'));
        if (s) box.appendChild(badge('unsaved', '● ' + s, s + ' unsaved'));
        if (c) box.appendChild(badge('changed', c, c + ' changed from code default'));
        return box;
    }
    function unitsIn(el) { return units.filter(function (u) { return el.contains(u.el); }); }

    // ── 7. collapse ───────────────────────────────────────────────────────
    var open = store.get('gc_open_sections');
    if (!Array.isArray(open)) open = ['wages'];
    function setOpen(sec, on) {
        sec.classList.toggle('is-collapsed', !on);
        var id = sec.getAttribute('data-section-id'), i = open.indexOf(id);
        if (on && i < 0) open.push(id);
        if (!on && i >= 0) open.splice(i, 1);
        store.set('gc_open_sections', open);
    }
    sections.forEach(function (sec) {
        sec.classList.toggle('is-collapsed', open.indexOf(sec.getAttribute('data-section-id')) < 0);
        var head = $('.bmc-card__head', sec);
        if (!head) return;
        head.setAttribute('role', 'button'); head.setAttribute('tabindex', '0');
        var toggle = function () { setOpen(sec, sec.classList.contains('is-collapsed')); head.setAttribute('aria-expanded', String(!sec.classList.contains('is-collapsed'))); };
        head.addEventListener('click', toggle);
        head.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
        sec._gcBadgeHost = document.createElement('span'); sec._gcBadgeHost.className = 'gc-section-summary';
        head.appendChild(sec._gcBadgeHost);
    });
    $('[data-gc-expand]', root).addEventListener('click', function () { sections.forEach(function (s) { setOpen(s, true); }); });
    $('[data-gc-collapse]', root).addEventListener('click', function () { sections.forEach(function (s) { setOpen(s, false); }); });

    // ── 8. nav ────────────────────────────────────────────────────────────
    var nav = $('#gc-nav');
    var navGroups = [];
    var lastKind = null;
    $$('.gc-group', root).forEach(function (g) {
        if (g.getAttribute('data-gc-empty')) { g.hidden = true; return; }
        var meta = GROUPS.filter(function (x) { return x.id === g.getAttribute('data-gc-group'); })[0] || { kind: '', label: '' };
        if (meta.kind !== lastKind) { var k = document.createElement('div'); k.className = 'gc-nav__kind'; k.textContent = meta.kind.toUpperCase(); nav.appendChild(k); lastKind = meta.kind; }
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'gc-nav__group';
        var lbl = document.createElement('span'); lbl.textContent = meta.label; btn.appendChild(lbl);
        btn.addEventListener('click', function () { g.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        nav.appendChild(btn);
        var list = document.createElement('div'); list.className = 'gc-nav__secs'; nav.appendChild(list);
        var secs = $$('.cfg-section', g).map(function (sec) {
            var a = document.createElement('a'); a.href = '#'; a.className = 'gc-nav__sec';
            var t = document.createElement('span'); t.textContent = titleOf(sec).toLowerCase().replace(/^./, function (c) { return c.toUpperCase(); }); a.appendChild(t);
            a.addEventListener('click', function (e) { e.preventDefault(); setOpen(sec, true); sec.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
            list.appendChild(a);
            return { sec: sec, link: a };
        });
        navGroups.push({ g: g, btn: btn, secs: secs });
    });

    // ── 9. search + filters ───────────────────────────────────────────────
    var q = $('#gc-q'), mode = 'all';
    $$('[data-gc-mode]', root).forEach(function (b) {
        b.addEventListener('click', function () {
            mode = b.getAttribute('data-gc-mode');
            $$('[data-gc-mode]', root).forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', String(on)); });
            apply();
        });
    });
    q.addEventListener('input', function () { apply(); });

    function unitMatches(u, terms) {
        if (mode === 'changed' && !u.changed) return false;
        if (mode === 'unsaved' && !u.unsaved) return false;
        if (mode === 'warn' && !(u.warn && u.warn.length)) return false;
        return terms.every(function (t) { return u.text.indexOf(t) >= 0; });
    }
    function apply() {
        var terms = q.value.toLowerCase().split(/\s+/).filter(Boolean);
        var filtering = terms.length > 0 || mode !== 'all';
        units.forEach(function (u) { u.el.classList.toggle('is-hidden', filtering && !unitMatches(u, terms)); });
        var anyGroup = false;
        navGroups.forEach(function (ng) {
            var anySec = false;
            ng.secs.forEach(function (s) {
                var us = unitsIn(s.sec);
                var vis = !filtering || us.some(function (u) { return !u.el.classList.contains('is-hidden'); });
                s.sec.classList.toggle('is-hidden', !vis);
                s.sec.classList.toggle('gc-force-open', filtering && vis);
                s.link.classList.toggle('is-hidden', !vis);
                if (vis) anySec = true;
            });
            ng.g.hidden = !anySec; ng.btn.classList.toggle('is-hidden', !anySec);
            if (anySec) anyGroup = true;
        });
        $('#gc-empty').hidden = anyGroup;
        renderBadges();
    }
    function renderBadges() {
        sections.forEach(function (sec) {
            if (!sec._gcBadgeHost) return;
            var us = unitsIn(sec);
            sec._gcBadgeHost.innerHTML = '';
            sec._gcBadgeHost.appendChild(badges(us));
        });
        navGroups.forEach(function (ng) {
            var old = $('.gc-badges', ng.btn); if (old) old.remove();
            ng.btn.appendChild(badges(unitsIn(ng.g)));
            ng.secs.forEach(function (s) { var o = $('.gc-badges', s.link); if (o) o.remove(); s.link.appendChild(badges(unitsIn(s.sec))); });
        });
        var cnt = function (m, fn) { var el = $('[data-gc-count="' + m + '"]', root); if (el) { var n = units.filter(fn).length; el.textContent = n ? String(n) : ''; } };
        cnt('changed', function (u) { return u.changed; });
        cnt('warn', function (u) { return u.warn && u.warn.length; });
        cnt('unsaved', function (u) { return u.unsaved; });
    }

    function reveal(u) {
        if (!u) return;
        if (u.el.classList.contains('is-hidden')) {
            q.value = ''; mode = 'all';
            $$('[data-gc-mode]', root).forEach(function (x) { var on = x.getAttribute('data-gc-mode') === 'all'; x.classList.toggle('is-on', on); x.setAttribute('aria-pressed', String(on)); });
            apply();
        }
        setOpen(u.section, true);
        u.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        u.el.classList.add('gc-flash');
        setTimeout(function () { u.el.classList.remove('gc-flash'); }, 1600);
        var f = u.fields[0]; if (f && f.focus) setTimeout(function () { f.focus({ preventScroll: true }); }, 400);
    }
    $$('[data-gc-goto]', root).forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var f = byName[a.getAttribute('data-gc-goto')];
            if (f) reveal(f._gcUnit);
        });
    });

    // ── 10. save bar: list every change ───────────────────────────────────
    var bar = $('[data-bmc-savebar]', form);
    var diff = document.createElement('ul'); diff.className = 'gc-diff'; diff.id = 'gc-diff';
    var toggleBtn = document.createElement('button'); toggleBtn.type = 'button'; toggleBtn.className = 'gc-link gc-diff-toggle'; toggleBtn.hidden = true;
    toggleBtn.setAttribute('aria-controls', 'gc-diff'); toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.addEventListener('click', function () { var on = !diff.classList.contains('is-open'); diff.classList.toggle('is-open', on); toggleBtn.setAttribute('aria-expanded', String(on)); renderDiff(); });
    if (bar) { var sub = $('.bmc-savebar__sub', bar); bar.insertBefore(toggleBtn, sub ? sub.nextSibling : null); bar.appendChild(diff); }
    function shown(f, v) { if (isTick(f)) return v === '1' ? 'on' : 'off'; if (v === '') return 'blank'; return v.length > 40 ? v.slice(0, 37) + '…' : v; }
    function renderDiff() {
        if (!bar) return;
        var dirty = fields.filter(isDirty);
        toggleBtn.hidden = dirty.length === 0;
        toggleBtn.textContent = diff.classList.contains('is-open') ? 'Hide changes' : 'Review ' + dirty.length + ' change' + (dirty.length === 1 ? '' : 's');
        if (!dirty.length) diff.classList.remove('is-open');
        diff.innerHTML = '';
        dirty.forEach(function (f) {
            var li = document.createElement('li');
            var a = document.createElement('a'); a.href = '#'; a.textContent = fieldLabel(f);
            a.addEventListener('click', function (e) { e.preventDefault(); reveal(f._gcUnit); });
            li.appendChild(a); li.appendChild(document.createTextNode(': '));
            var s = document.createElement('s'); s.textContent = shown(f, savedOf(f)); li.appendChild(s);
            li.appendChild(document.createTextNode(' → '));
            var b = document.createElement('b'); b.textContent = shown(f, normOf(f)); li.appendChild(b);
            diff.appendChild(li);
        });
    }

    // ── wire up ───────────────────────────────────────────────────────────
    var timer = null;
    function refreshAll() {
        units.forEach(refreshUnit);
        fields.forEach(function (f) { if (f._gcJson) f._gcJson(); });
        runChecks(); apply(); renderDiff();
    }
    function onEdit(e) {
        var f = e.target; if (!f || !f._gcUnit) return;
        refreshUnit(f._gcUnit);
        if (f._gcJson) f._gcJson();
        clearTimeout(timer);
        timer = setTimeout(function () { runChecks(); apply(); renderDiff(); }, 120);
    }
    form.addEventListener('input', onEdit);
    form.addEventListener('change', onEdit);
    form.addEventListener('bmc:discard', function () { setTimeout(refreshAll, 0); });
    $$('.bmc-revert', form).forEach(function (b) { b.addEventListener('click', function () { setTimeout(refreshAll, 0); }); });

    refreshAll();
    if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } });
}());
