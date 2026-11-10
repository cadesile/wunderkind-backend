/**
 * Build My Club admin — shared behaviour for the redesigned NON-LIST pages.
 * Target: public/bmc-pages.js, loaded after bmc-admin.js on every admin page
 * (configureAssets()->addJsFile('bmc-pages.js')). Plain ES5, no build step.
 *
 * Everything is opt-in by data attribute, so loading it on a stock page is a no-op.
 *
 *   BMC.toast(msg)                         stacked toast, 2.5s hold, 300ms stepped fade
 *   BMC.confirm({title, message, confirm, variant}) -> Promise<boolean>   PixelDialog
 *   BMC.runJobs({url, token, jobs, onUpdate}) -> Promise<jobs>            sequential chunk POSTs
 *                                          job: {type?, mode?, nationality?, fields?, label}
 *   BMC.money(el) / BMC.digits(str)        thousands-separator formatting helpers
 *   BMC.refreshForm(form)                  re-run dirty/validation after scripted changes
 *
 * Declarative hooks:
 *   [data-bmc-tabs]            role=tablist; each [role=tab][aria-controls] toggles its panel
 *   [data-bmc-radio]           a .bmc-segs group of buttons; sets aria-checked, fires "bmc:change"
 *   form[data-bmc-form]        dirty tracking, validation, revert, save bar (see below)
 *   [data-bmc-dialog-title]    on a submit button or link: confirm through the PixelDialog first
 *                              (+ data-bmc-dialog-message / -confirm / -variant)
 *
 * form[data-bmc-form] contract
 *   input[data-saved="40"]          saved value (string). Changed fields get .is-changed on .bmc-num.
 *   data-min / data-max             integer bounds (inclusive)
 *   data-kind="int"|"money"         money = whole pounds, shown with thousands separators, posted bare
 *   data-blank="ok"                 empty is allowed (posts "")
 *   data-lte="otherName"            this <= other   (min/max pairs; reported on the row)
 *   data-between="minName,maxName"  min <= this <= max (potential mean)
 *   data-nonzero-group="pos"        at least one field in the group must be > 0 (position weights)
 *   [data-bmc-setting]              a row: owns its fields, one .bmc-revert and one [data-bmc-hint]
 *   [data-bmc-hint][data-default]   the row's idle hint; replaced by the error / "Saved: …" text
 *   [data-bmc-savebar]              .bmc-savebar__title / __sub, [data-bmc-save], [data-bmc-discard]
 */
(function () {
    'use strict';

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var icons = function () { if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 2 } }); };
    var BMC = window.BMC = window.BMC || {};

    // ── toast ───────────────────────────────────────────────────────────
    BMC.toast = function (msg) {
        var box = $('.bmc-toasts');
        if (!box) {
            var host = $('.bmc-overlay') || document.body;
            box = document.createElement('div'); box.className = 'bmc-toasts';
            host.appendChild(box);
        }
        var t = document.createElement('div');
        t.className = 'bmc-toast'; t.setAttribute('role', 'status'); t.textContent = String(msg).toUpperCase();
        box.appendChild(t);
        setTimeout(function () { t.classList.add('is-leaving'); setTimeout(function () { t.remove(); }, 300); }, 2500);
    };

    // ── dialog ──────────────────────────────────────────────────────────
    // Markup lives in templates/admin/_bmc_dialog.html.twig (#bmc-dialog), included once by bmc_page.
    BMC.confirm = function (o) {
        var scrim = $('#bmc-dialog');
        if (!scrim) return Promise.resolve(window.confirm((o.title || '') + '\n\n' + (o.message || '')));
        $('.bmc-dialog__title', scrim).textContent = o.title || 'CONFIRM';
        var msg = $('.bmc-dialog__msg', scrim);
        msg.textContent = o.message || ''; msg.hidden = !o.message;
        var ok = $('[data-bmc-dialog-ok]', scrim);
        ok.className = 'bmc-btn bmc-btn--' + (o.variant || 'yellow');
        $('span', ok).textContent = (o.confirm || 'CONFIRM').toUpperCase();
        scrim.hidden = false; ok.focus();
        return new Promise(function (resolve) {
            function done(v) {
                scrim.hidden = true;
                ok.removeEventListener('click', yes); cancel.removeEventListener('click', no);
                scrim.removeEventListener('click', bg); document.removeEventListener('keydown', key);
                resolve(v);
            }
            var cancel = $('[data-bmc-dialog-cancel]', scrim);
            var yes = function () { done(true); }, no = function () { done(false); };
            var bg = function (e) { if (e.target === scrim) done(false); };
            var key = function (e) { if (e.key === 'Escape') done(false); };
            ok.addEventListener('click', yes); cancel.addEventListener('click', no);
            scrim.addEventListener('click', bg); document.addEventListener('keydown', key);
        });
    };
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-bmc-dialog-title]');
        if (!b || b.hasAttribute('data-bmc-dialog-ok-once')) { if (b) b.removeAttribute('data-bmc-dialog-ok-once'); return; }
        if (b.disabled) return;
        e.preventDefault();
        BMC.confirm({
            title: b.getAttribute('data-bmc-dialog-title'), message: b.getAttribute('data-bmc-dialog-message'),
            confirm: b.getAttribute('data-bmc-dialog-confirm'), variant: b.getAttribute('data-bmc-dialog-variant')
        }).then(function (yes) {
            if (!yes) return;
            if (b.form && (b.type === 'submit' || b.tagName === 'BUTTON')) { b.form.requestSubmit ? b.form.requestSubmit(b) : b.form.submit(); }
            else { b.setAttribute('data-bmc-dialog-ok-once', ''); b.click(); }
        });
    }, true);

    // ── tabs + radio segments ──────────────────────────────────────────
    $$('[data-bmc-tabs]').forEach(function (list) {
        var tabs = $$('[role=tab]', list);
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                tabs.forEach(function (x) {
                    var on = x === t; x.setAttribute('aria-selected', on ? 'true' : 'false');
                    var p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on;
                });
                list.dispatchEvent(new CustomEvent('bmc:change', { bubbles: true, detail: { value: t.getAttribute('data-value') } }));
            });
        });
    });
    $$('[data-bmc-radio]').forEach(function (g) {
        $$('button', g).forEach(function (b) {
            b.addEventListener('click', function () {
                $$('button', g).forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
                g.dispatchEvent(new CustomEvent('bmc:change', { bubbles: true, detail: { value: b.getAttribute('data-value') } }));
            });
        });
    });

    // ── money ───────────────────────────────────────────────────────────
    BMC.digits = function (s) { return String(s == null ? '' : s).replace(/[^\d]/g, ''); };
    BMC.money = function (el) {
        var d = BMC.digits(el.value);
        var atEnd = el.selectionStart === el.value.length;
        el.value = d ? Number(d).toLocaleString('en-GB') : '';
        if (atEnd) el.setSelectionRange(el.value.length, el.value.length);
    };

    // ── form state ──────────────────────────────────────────────────────
    function norm(el) { return el.getAttribute('data-kind') === 'money' ? BMC.digits(el.value) : String(el.value).trim(); }
    function savedOf(el) { var s = el.getAttribute('data-saved'); return el.getAttribute('data-kind') === 'money' ? BMC.digits(s) : String(s == null ? '' : s).trim(); }
    function intOf(el) { var v = norm(el); return /^-?\d+$/.test(v) ? parseInt(v, 10) : null; }

    function fieldError(el) {
        var v = norm(el), kind = el.getAttribute('data-kind') || 'int';
        if (v === '') return el.getAttribute('data-blank') === 'ok' ? '' : 'Required';

        // TWO KINDS BEYOND int/money, both added for Starter config. `text` is a value with no
        // numeric meaning at all — a <select>'s option, a JSON blob — where the only rule worth
        // enforcing is required-or-not; running the integer check over "MEDIUM" reported every
        // dropdown on the page as broken. `decimal` is a real number: quizBonusMultiplier steps
        // in 0.05 and the fan-base multipliers in 0.01, so "0.2" is correct input and the
        // integer check rejected it.
        if (kind === 'text') return '';

        if (kind === 'decimal') {
            if (!/^-?\d*\.?\d+$/.test(v)) return 'Number only';
            var d = parseFloat(v), dlo = el.getAttribute('data-min'), dhi = el.getAttribute('data-max');
            if ((dlo !== null && d < +dlo) || (dhi !== null && d > +dhi)) return dlo + '–' + dhi + ' only';
            return '';
        }

        // SIGNED INTEGERS. Money is unsigned by nature, but plenty of int settings sit below
        // zero on purpose — bondMin (-100), the bond/trust thresholds (-20) — and the
        // digits-only test reported every one of them as 'Whole number' on its saved value,
        // pinning the save bar to invalid before the operator touched anything.
        if (!(kind === 'money' ? /^\d+$/ : /^-?\d+$/).test(v)) return kind === 'money' ? 'Whole pounds only' : 'Whole number';
        var n = parseInt(v, 10), lo = el.getAttribute('data-min'), hi = el.getAttribute('data-max');
        if ((lo !== null && n < +lo) || (hi !== null && n > +hi)) return lo + '–' + hi + ' only';
        return '';
    }

    // SELECTS AND TEXTAREAS COUNT TOO. This was input-only, which is fine while every tracked
    // field is a number — and silently wrong the moment a page's only change is a dropdown or a
    // JSON blob: check() saw nothing dirty, so the save bar read ALL SAVED and, worse,
    // [data-bmc-save] stayed DISABLED and the operator could not save at all. Starter config is
    // four selects and two JSON editors, so it would have been unusable.
    var FIELD_SEL = 'input[data-saved], select[data-saved], textarea[data-saved]';

    function bindForm(form) {
        var fields = $$(FIELD_SEL, form);
        var bar = $('[data-bmc-savebar]', form) || $('[data-bmc-savebar][data-form="' + form.id + '"]');
        var byName = {}; fields.forEach(function (f) { byName[f.name] = f; });

        function check() {
            var changed = 0, invalid = 0;
            fields.forEach(function (f) { f._err = fieldError(f); });
            // cross-field rules
            fields.forEach(function (f) {
                var lte = f.getAttribute('data-lte'), btw = f.getAttribute('data-between');
                if (lte && byName[lte] && !f._err && !byName[lte]._err) {
                    var a = intOf(f), b = intOf(byName[lte]);
                    if (a !== null && b !== null && a > b) { f._err = 'Min is above max'; byName[lte]._err = byName[lte]._err || ' '; }
                }
                if (btw && !f._err) {
                    var p = btw.split(','), lo = byName[p[0]], hi = byName[p[1]], v = intOf(f);
                    if (lo && hi && v !== null && (v < intOf(lo) || v > intOf(hi))) f._err = 'Mean must sit inside the range';
                }
            });
            var groups = {};
            fields.forEach(function (f) { var g = f.getAttribute('data-nonzero-group'); if (g) (groups[g] = groups[g] || []).push(f); });
            Object.keys(groups).forEach(function (g) {
                var any = groups[g].some(function (f) { return (intOf(f) || 0) > 0; });
                var msg = $('[data-bmc-group-error="' + g + '"]', form);
                if (msg) msg.hidden = any;
                if (!any) groups[g].forEach(function (f) { f._err = f._err || 'At least one weight must be above 0.'; });
            });

            fields.forEach(function (f) {
                var dirty = norm(f) !== savedOf(f), box = f.closest('.bmc-num') || f.closest('.bmc-textbox') || f.closest('.bmc-trophy-btn');
                f._dirty = dirty;
                if (box) { box.classList.toggle('is-changed', dirty && !f._err); box.classList.toggle('is-invalid', !!f._err); }
                if (dirty) changed++;
                if (f._err) invalid++;
            });
            $$('[data-bmc-setting]', form).forEach(function (row) {
                var fs = $$(FIELD_SEL, row);
                var err = fs.map(function (f) { return f._err; }).filter(function (x) { return x && x.trim(); })[0];
                var dirty = fs.some(function (f) { return f._dirty; });
                var rv = $('.bmc-revert', row); if (rv) rv.hidden = !dirty;
                var hint = $('[data-bmc-hint]', row);
                if (hint) {
                    hint.classList.remove('bmc-hint--invalid', 'bmc-hint--changed');
                    if (err) { hint.textContent = err; hint.classList.add('bmc-hint--invalid'); }
                    else if (dirty) { hint.textContent = 'Saved: ' + (hint.getAttribute('data-saved-text') || fs.map(savedOf).join('–')); hint.classList.add('bmc-hint--changed'); }
                    else hint.textContent = hint.getAttribute('data-default') || '';
                }
            });
            form._bmcDirty = changed > 0;
            if (bar) {
                bar.setAttribute('data-state', invalid ? 'invalid' : changed ? 'dirty' : 'clean');
                $('.bmc-savebar__title', bar).textContent = invalid ? invalid + (invalid === 1 ? ' FIELD NEEDS' : ' FIELDS NEED') + ' FIXING'
                    : changed ? changed + (changed === 1 ? ' UNSAVED CHANGE' : ' UNSAVED CHANGES') : 'ALL SAVED';
                $('.bmc-savebar__sub', bar).textContent = invalid ? 'Red fields are out of range. Fix them to save.'
                    : changed ? 'Blue fields differ from what is saved. Saving applies from the next run.' : (bar.getAttribute('data-idle') || 'Nothing to save.');
                var save = $('[data-bmc-save]', bar), discard = $('[data-bmc-discard]', bar);
                if (save) save.disabled = !changed || invalid > 0;
                if (discard) discard.disabled = !changed;
            }
            form.dispatchEvent(new CustomEvent('bmc:state', { detail: { changed: changed, invalid: invalid } }));
        }

        fields.forEach(function (f) {
            if (f.getAttribute('data-kind') === 'money') { BMC.money(f); f.addEventListener('input', function () { BMC.money(f); }); }
            f.addEventListener('input', check);
            f.addEventListener('change', check);   // <select> is change-only
        });
        $$('[data-bmc-setting]', form).forEach(function (row) {
            var rv = $('.bmc-revert', row);
            if (rv) rv.addEventListener('click', function () {
                $$(FIELD_SEL, row).forEach(function (f) { f.value = f.getAttribute('data-saved'); if (f.getAttribute('data-kind') === 'money') BMC.money(f); });
                check();
            });
        });
        if (bar) {
            var discard = $('[data-bmc-discard]', bar);
            if (discard) discard.addEventListener('click', function () {
                fields.forEach(function (f) { f.value = f.getAttribute('data-saved'); if (f.getAttribute('data-kind') === 'money') BMC.money(f); });
                check(); form.dispatchEvent(new CustomEvent('bmc:discard'));
            });
        }
        // Money posts bare digits: LeagueAdminController::quickEdit casts with (int), so "1,000" would save £1.
        form.addEventListener('submit', function () {
            form._bmcSubmitting = true;
            $$('input[data-kind="money"]', form).forEach(function (f) { f.value = BMC.digits(f.value); });
        });
        form._bmcCheck = check;
        check();
    }
    $$('form[data-bmc-form]').forEach(bindForm);
    BMC.refreshForm = function (form) { if (form && form._bmcCheck) form._bmcCheck(); };
    window.addEventListener('beforeunload', function (e) {
        var dirty = $$('form[data-bmc-form]').some(function (f) { return f._bmcDirty && !f._bmcSubmitting; });
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // ── job runner (pool generate-chunk endpoints) ─────────────────────
    // jobs: [{label, type, mode, nationality}] — one POST each, strictly in sequence, because
    // each chunk is a synchronous generation on the server. onUpdate(jobs) after every change;
    // job.status: queued | running | done | skipped | failed, job.count, job.message.
    BMC.runJobs = function (o) {
        var jobs = o.jobs.map(function (j) { return Object.assign({ status: 'queued', count: 0, message: '' }, j); });
        var up = function () { if (o.onUpdate) o.onUpdate(jobs); };
        up();
        var i = 0;
        function next() {
            if (i >= jobs.length) return Promise.resolve(jobs);
            var j = jobs[i]; j.status = 'running'; up();
            var body = new FormData();
            body.append('_token', o.token);
            // type/mode/nationality are the pool endpoints' vocabulary and stay for them.
            if (j.type) body.append('type', j.type);
            if (j.mode) body.append('mode', j.mode);
            if (j.nationality) body.append('nationality', j.nationality);
            // Anything else the endpoint needs. The location reclassifier posts `country`
            // this way, so the runner drives it without learning a second vocabulary.
            if (j.fields) { Object.keys(j.fields).forEach(function (k) { body.append(k, j.fields[k]); }); }
            return fetch(o.url, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { success: false, message: 'HTTP ' + r.status }; }); })
                .then(function (d) {
                    j.status = !d.success ? 'failed' : d.skipped ? 'skipped' : 'done';
                    j.count = d.count || 0; j.message = d.message || '';
                })
                .catch(function (err) { j.status = 'failed'; j.message = String(err); })
                .then(function () { up(); i++; return next(); });
        }
        return next();
    };

    icons();
}());
