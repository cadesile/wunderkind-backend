/* ══════════════════════════════════════════════════════════════════════════
   Build My Club — admin dashboard

   The page ships as a shell; each panel pulls its own JSON from
   AdminStatsController. Chart colours are read from the CSS custom properties
   in admin-dashboard.css so the charts track the theme rather than carrying
   their own hex list.

   Charting is deliberately sparing. Small facets (≤ 8 categories) render as
   labelled meter lists: exact values stay visible without a hover, which is
   both more accessible and far denser than eight near-empty canvases. Only
   nationality — the one facet with enough categories to need a chart — and the
   growth trend get a real canvas.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var root = document.querySelector('[data-dashboard]');
    if (!root) { return; }

    var REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var CHART_THRESHOLD = 8;   // more categories than this earns a canvas
    var CHART_MAX_BARS = 15;   // beyond this a bar chart stops being readable

    // ── Theme ────────────────────────────────────────────────────────────
    function cssVar(name, fallback) {
        var v = getComputedStyle(root).getPropertyValue(name).trim();
        return v || fallback;
    }

    var PALETTE = (function () {
        var out = [];
        // Was --dash-chart-N from admin-dashboard.css, which is retired. The shell's own
        // palette is the source now.
        ['--wk-accent', '--wk-positive', '--wk-info-light', '--wk-warning', '--wk-danger']
            .forEach(function (name) { out.push(cssVar(name, '#E8CF59')); });
        return out;
    })();
    
    var TICK = cssVar('--wk-text-dim', '#9bb0c4');
    var ACCENT = cssVar('--accent', '#E8CF59');
    var DANGER = cssVar('--bs-danger', '#C44747');

    function colorAt(i) { return PALETTE[i % PALETTE.length]; }
    function alpha(hex, a) { return hex + a; }

    // Chart.js is gone. The design asks for stepped sparklines behind the numbers and
    // plain bars; both are cheaper as inline SVG and a div, and a smoothed curve between two
    // daily counts implies readings that were never taken.

    var charts = [];

    // ── Fetch (deduped: the KPI band and the growth panel share a payload) ──
    var inflight = {};

    function load(url, force) {
        if (force) { delete inflight[url]; }
        if (!inflight[url]) {
            inflight[url] = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.json();
                })
                .catch(function (err) { delete inflight[url]; throw err; });
        }
        return inflight[url];
    }

    function refreshCache(panelKey) {
        var body = new URLSearchParams();
        body.set('_token', root.dataset.refreshToken);
        if (panelKey) { body.set('panel', panelKey); }

        return fetch(root.dataset.refreshUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
    }

    // ── DOM helpers ──────────────────────────────────────────────────────
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function num(n) { return Number(n || 0).toLocaleString(); }

    /** Big counts get an abbreviated form so a KPI tile never wraps. */
    function compact(n) {
        n = Number(n || 0);
        if (n >= 1e9) { return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B'; }
        if (n >= 1e6) { return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M'; }
        if (n >= 1e5) { return Math.round(n / 1e3) + 'K'; }
        return n.toLocaleString();
    }

    /**
     * Facet keys are a mix of enum values (`facility_manager`), demonyms
     * (`English`) and band labels (`26-30`). Only the first kind needs
     * prettifying — titleizing the rest would mangle them.
     */
    function prettyKey(k) {
        k = String(k);
        return k.indexOf('_') === -1 ? k : titleize(k);
    }

    function titleize(s) {
        return String(s).replace(/([a-z])([A-Z])/g, '$1 $2')
            .replace(/[_-]/g, ' ')
            .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function stamp(node, iso) {
        if (!node) { return; }
        var d = iso ? new Date(iso) : new Date();
        node.textContent = 'updated ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function busy(panel, on) {
        panel.querySelectorAll('[data-panel-refresh],[data-pool-refresh]').forEach(function (b) {
            b.classList.toggle('is-busy', !!on);
            b.disabled = !!on;
        });
    }

    /*
       Meters and the ranked bar chart are single-hue on purpose. Each of these
       shows one measure across categories, so a different colour per row would
       encode nothing and just add noise. Colour is reserved for the one place
       it does carry meaning: the KPI composition strip, where the swatch is the
       only link between a segment and its legend label.
    */
    // ── Meter list (the default facet renderer) ──────────────────────────
    function meterList(facet, opts) {
        opts = opts || {};
        var total = facet.reduce(function (a, r) { return a + r.count; }, 0) || 1;
        var max = opts.max || facet.reduce(function (a, r) { return Math.max(a, r.count); }, 0) || 1;

        var wrap = el('div');
        wrap.style.display = 'flex';
        wrap.style.flexDirection = 'column';
        wrap.style.gap = '6px';

        facet.forEach(function (row) {
            var m = el('div', 'bmc-meter');

            m.appendChild(el('span', 'bmc-celltext', prettyKey(row.key)));
            m.appendChild(el('span', 'bmc-cellnum', num(row.count)));
            m.appendChild(el('span', 'bmc-data', ((row.count / total) * 100).toFixed(1) + '%'));

            var track = el('span', 'bmc-bar bmc-bar--8');
            var fill = el('span', 'bmc-bar__fill');
            fill.style.width = (max ? (row.count / max) * 100 : 0).toFixed(2) + '%';
            if (opts.color) { fill.style.background = opts.color; }
            track.appendChild(fill);
            m.appendChild(track);

            wrap.appendChild(m);
        });

        return wrap;
    }

    // ── Charts ───────────────────────────────────────────────────────────
    /** Horizontal bars, sorted descending, with the value printed on each bar. */
    /**
     * The top categories as horizontal bars, widest first.
     *
     * Was a Chart.js bar canvas. Single-hue on purpose: one measure across categories, so a
     * colour per row would encode nothing. Colour is reserved for the KPI composition strip,
     * where the swatch is the only link between a segment and its legend.
     */
    function rankedBarChart(box, facet) {
        var rows = facet.slice().sort(function (a, b) { return b.count - a.count; }).slice(0, CHART_MAX_BARS);
        var max = rows.length ? rows[0].count : 1;

        box.textContent = '';
        box.appendChild(meterList(rows, { max: max }));
    }

    /**
     * A stepped sparkline, as inline SVG, sized by its box.
     *
     * STEPPED, NOT SMOOTHED. These are daily counts: a curve between two days draws a reading
     * that was never taken. The design says the same thing — motion and line are stepped or
     * nothing.
     *
     * DECORATION, NOT A CHART. It sits behind the number with no axis and no labels, so it
     * carries a viewBox and stretches; there is nothing to misread as a precise value.
     */
    function sparkline(box, series, color) {
        var n = series.length;
        if (!n) { box.innerHTML = ''; return; }

        var max = Math.max.apply(null, series) || 1;
        var w = 100, h = 28, step = n > 1 ? w / (n - 1) : w;
        var d = '';

        series.forEach(function (v, i) {
            var x = i * step;
            var y = h - (v / max) * h;
            // Across at the previous height, then up: one flat run per day.
            d += i === 0 ? 'M0,' + y.toFixed(2) : 'H' + x.toFixed(2) + 'V' + y.toFixed(2);
        });
        d += 'H' + w;

        box.innerHTML =
            '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" focusable="false">' +
            '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="1.5" vector-effect="non-scaling-stroke"/>' +
            '</svg>';
    }

    /** True when a series has at least one non-zero point, i.e. something to plot. */
    function hasSignal(series) {
        return series.some(function (v) { return Number(v) > 0; });
    }


    // ── KPI band ─────────────────────────────────────────────────────────
    var SPARK_COLOR = { invalidSyncs: DANGER };

    function paintKpis(growth) {
        Object.keys(growth.metrics).forEach(function (metric) {
            var tile = root.querySelector('[data-kpi="' + metric + '"]');
            if (!tile) { return; }

            var data = growth.metrics[metric];
            var value = tile.querySelector('[data-kpi-value]');
            value.textContent = compact(data.all);
            value.title = num(data.all);
            value.classList.remove('is-muted');

            tile.querySelectorAll('[data-window]').forEach(function (node) {
                var v = data[node.dataset.window] || 0;
                node.textContent = v > 0 ? '+' + compact(v) : '0';
                node.classList.toggle('is-zero', v === 0);
            });

            if (metric === 'users') {
                tile.querySelector('[data-kpi-sub]').textContent =
                    num(data.registered) + ' registered · ' + num(data.guest) + ' guest';
            } else if (metric === 'activeClubs') {
                tile.querySelector('[data-kpi-sub]').textContent = 'distinct clubs syncing';
            } else if (metric === 'invalidSyncs') {
                var all = growth.metrics.syncs.all;
                tile.classList.toggle('is-clean', data.all === 0);
                tile.querySelector('[data-kpi-sub]').textContent = all
                    ? ((data.all / all) * 100).toFixed(2) + '% of all syncs rejected'
                    : 'no syncs recorded yet';
            }

            var spark = tile.querySelector('[data-kpi-spark]');
            var series = growth.trend.series[metric];
            if (spark && series) {
                        // An all-zero series has no shape to draw. Chart.js renders it as a
                // single flat stroke along the baseline, which spans the whole tile and
                // reads as a rule drawn across the dashboard rather than as a chart.
                // On invalidSyncs that stroke is DANGER red, so a perfectly healthy
                // system (zero rejected syncs) painted a red line — alarming, and
                // exactly backwards. Draw nothing instead; the tile's own value, its
                // is-clean state and its sub-label already say "none".
                // Side effect worth keeping: because this only draws when a value is
                // non-zero, red now appears on invalidSyncs only when there really are
                // invalid syncs.
                if (hasSignal(series)) {
                    sparkline(spark, series, SPARK_COLOR[metric] || ACCENT);
                } else {
                    spark.innerHTML = '';
                }
            }
        });
    }

    /** Inventory tiles: a standing count plus a composition strip. */
    function paintStockKpi(metric, data, facetName) {
        var tile = root.querySelector('[data-kpi-stock="' + metric + '"]');
        if (!tile) { return; }

        var value = tile.querySelector('[data-kpi-value]');
        value.textContent = compact(data.total);
        value.title = num(data.total);
        value.classList.remove('is-muted');

        var facet = (data.facets && data.facets[facetName]) || [];
        var shown = facet.slice().sort(function (a, b) { return b.count - a.count; }).slice(0, 4);
        var total = facet.reduce(function (a, r) { return a + r.count; }, 0) || 1;

        var strip = tile.querySelector('[data-kpi-strip]');
        var legend = tile.querySelector('[data-kpi-legend]');
        strip.textContent = '';
        legend.textContent = '';

        shown.forEach(function (row, i) {
            var seg = el('i');
            seg.style.flex = row.count;
            seg.style.background = colorAt(i);
            strip.appendChild(seg);

            var item = el('span');
            var swatch = el('i');
            swatch.style.background = colorAt(i);
            item.appendChild(swatch);
            item.appendChild(document.createTextNode(prettyKey(row.key) + ' ' + ((row.count / total) * 100).toFixed(0) + '%'));
            legend.appendChild(item);
        });

        if (metric === 'agents' && data.unagentedPlayers !== undefined) {
            tile.querySelector('[data-kpi-sub]').textContent = num(data.unagentedPlayers) + ' players unrepresented';
        }
    }

    // ── Growth panel ─────────────────────────────────────────────────────
    /**
     * Growth as small multiples: one stepped row per series, each on its own scale.
     *
     * WAS ONE CHART WITH THREE LINES SHARING AN AXIS, which is the problem — syncs dwarf
     * users, so the two that matter sat flat along the bottom. Per-row scaling trades
     * comparability between rows for readability within one, and the heading says so, because
     * a reader who assumes a shared axis would draw the wrong conclusion.
     */
    function renderGrowth(container, data) {
        container.textContent = '';

        var wrap = el('div');
        wrap.style.display = 'flex';
        wrap.style.flexDirection = 'column';
        wrap.style.gap = '10px';

        ['users', 'clubs', 'syncs'].forEach(function (key, i) {
            var series = data.trend.series[key] || [];
            var peak = series.length ? Math.max.apply(null, series) : 0;
            var last = series.length ? series[series.length - 1] : 0;

            var row = el('div', 'bmc-sparkrow');
            row.appendChild(el('span', 'bmc-lbl', titleize(key)));

            var box = el('span', 'bmc-spark bmc-spark--row');
            if (hasSignal(series)) {
                sparkline(box, series, SPARK_COLOR[key] || colorAt(i));
            }
            row.appendChild(box);

            row.appendChild(el('span', 'bmc-cellnum', num(last)));
            row.appendChild(el('span', 'bmc-data', 'peak ' + num(peak)));

            wrap.appendChild(row);
        });

        container.appendChild(wrap);

        var foot = el('p', 'bmc-data', 'Daily · last ' + (data.trend.days || []).length + ' days · each row on its own scale');
        container.appendChild(foot);
    }

    // ── Leaderboards panel ───────────────────────────────────────────────
    function renderLeaderboards(container, data) {
        container.textContent = '';

        var tabs = el('div', 'bmc-segs');
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'Leaderboard category');

        var region = el('div');
        region.setAttribute('role', 'tabpanel');
        region.setAttribute('aria-live', 'polite');

        function paint(board) {
            region.textContent = '';

            if (!board.entries.length) {
                region.appendChild(el('span', 'bmc-empty-note',
                    'NO RANKED CLUBS YET · BOARDS ONLY COUNT CONCLUDED SEASONS'));
                return;
            }

            var rows = el('div', 'bmc-rows bmc-rows--flush');

            board.entries.forEach(function (row) {
                var tr = el('div', 'bmc-rankrow');

                // Rank 1 is the one place gold appears here — the design rations it.
                var rank = el('span', 'bmc-tierchip' + (row.rank === 1 ? ' is-first' : ''), String(row.rank));
                tr.appendChild(rank);

                tr.appendChild(el('span', 'bmc-celltext', row.clubName));
                tr.appendChild(el('span', 'bmc-cellnum', num(row.score)));
                tr.appendChild(el('span', 'bmc-data', row.displayLabel || '—'));

                rows.appendChild(tr);
            });

            region.appendChild(rows);
            region.appendChild(el('p', 'bmc-data',
                num(board.total) + ' ranked club' + (board.total === 1 ? '' : 's') + ' in this board.'));
        }

        data.boards.forEach(function (board, i) {
            var btn = el('button', null, board.label);
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-checked', i === 0 ? 'true' : 'false');
            btn.addEventListener('click', function () {
                tabs.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-checked', 'false'); });
                btn.setAttribute('aria-checked', 'true');
                paint(board);
            });
            tabs.appendChild(btn);
            if (i === 0) { paint(board); }
        });

        container.appendChild(tabs);
        container.appendChild(region);
    }

    // ── Pool panel ───────────────────────────────────────────────────────
    function renderPool(container, data) {
        destroyIn(container);
        container.textContent = '';

        if (!data.total) {
            container.appendChild(el('span', 'bmc-empty-note', 'NOTHING IN THIS POOL YET'));
            return;
        }

        container.appendChild(poolSummary(data));

        var grid = el('div', 'facet-grid');
        Object.keys(data.facets).forEach(function (name) {
            var facet = data.facets[name];
            if (!facet.length) { return; }

            // The nested table below already renders this dimension in full,
            // with counts, share and drill-down. A chart of the same numbers
            // directly above it is duplication, not a second view.
            if (name === data.nested.dimension) { return; }

            var big = facet.length > CHART_THRESHOLD;
            var cell = el('div', 'facet' + (big ? ' facet--wide' : ''));
            cell.appendChild(el('h3', null, titleize(name)));

            if (big) {
                var holder = el('div', 'facet-canvas');
                var canvas = document.createElement('canvas');
                holder.appendChild(canvas);
                cell.appendChild(holder);
                // Deferred so the canvas has laid out before Chart.js measures it.
                requestAnimationFrame(function () { rankedBarChart(canvas, facet); });
                if (facet.length > CHART_MAX_BARS) {
                    cell.appendChild(el('p', 'bmc-data', 'Top ' + CHART_MAX_BARS + ' of ' + facet.length + ' — the full list is in the table below.'));
                }
            } else {
                cell.appendChild(meterList(facet));
            }

            grid.appendChild(cell);
        });
        container.appendChild(grid);

        container.appendChild(renderNested(data.nested, data.total));
    }

    function poolSummary(data) {
        var wrap = el('div', 'pool-summary');

        function stat(label, value, warn) {
            var s = el('div', 'pool-stat' + (warn ? ' is-warn' : ''));
            s.appendChild(el('span', 's-label', label));
            s.appendChild(el('span', 's-value', value));
            wrap.appendChild(s);
        }

        stat('In pool', num(data.total));

        if (data.unagentedPlayers !== undefined) {
            stat('Unrepresented players', num(data.unagentedPlayers), data.unagentedPlayers > 0);
        }
        if (data.summary) {
            stat('Leagues', num(data.summary.leagues));
            stat('Cached world packs', num(data.summary.cachedWorldPacks));
            stat('Stale packs', num(data.summary.staleWorldPacks), data.summary.staleWorldPacks > 0);
        }

        return wrap;
    }

    /**
     * Collapsible tree table — the primary drill-down view. Chosen over a
     * treemap/sunburst deliberately: exact counts stay visible, and every row
     * is reachable by keyboard.
     */
    function renderNested(nested, total) {
        var wrap = el('div');
        wrap.style.overflowX = 'auto';
        var table = el('table', 'bmc-minitable');

        var thead = el('thead');
        var hr = el('tr');
        [[titleize(nested.dimension), ''], ['Count', 'col-count num'], ['Share', 'col-share num'], ['', 'col-meter']].forEach(function (h) {
            hr.appendChild(el('th', h[1], h[0]));
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        var tbody = el('tbody');

        var max = nested.rows.reduce(function (a, r) { return Math.max(a, r.count); }, 0) || 1;

        nested.rows.forEach(function (row, i) {
            var pct = total ? (row.count / total) * 100 : 0;
            var childId = 'nested-' + nested.dimension + '-' + i;

            var tr = el('tr');

            var tdKey = el('td');
            var btn = el('button', 'bmc-quick-btn');
            btn.type = 'button';
            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-controls', childId);
            btn.appendChild(el('i', 'fa fa-chevron-right caret'));
            btn.appendChild(el('span', null, prettyKey(row.key)));
            tdKey.appendChild(btn);
            tr.appendChild(tdKey);

            tr.appendChild(el('td', 'col-count num', num(row.count)));
            tr.appendChild(el('td', 'col-share num', pct.toFixed(1) + '%'));

            var tdMeter = el('td', 'col-meter');
            var track = el('div', 'm-track');
            var fill = el('i');
            fill.style.width = Math.max((row.count / max) * 100, 1).toFixed(2) + '%';
            fill.style.background = ACCENT;
            track.appendChild(fill);
            tdMeter.appendChild(track);
            tr.appendChild(tdMeter);

            tbody.appendChild(tr);

            var childTr = el('tr');
            childTr.hidden = true;
            childTr.id = childId;
            var childTd = el('td');
            childTd.colSpan = 4;

            var cg = el('div', 'child-grid');
            nested.children.forEach(function (dim) {
                var col = el('div');
                col.appendChild(el('h4', null, titleize(dim)));
                var rows = row.children[dim] || [];
                col.appendChild(rows.length ? meterList(rows) : el('span', 'bmc-data', '—'));
                cg.appendChild(col);
            });

            childTd.appendChild(cg);
            childTr.appendChild(childTd);
            tbody.appendChild(childTr);

            btn.addEventListener('click', function () {
                var open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                // `hidden` rather than a class, to match the panel slots.
                childTr.hidden = open;
            });
        });

        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }

    // ── Panel wiring ─────────────────────────────────────────────────────
    var RENDERERS = {
        'panel-growth': renderGrowth,
        'panel-leaderboards': renderLeaderboards
    };

    function bindPanel(panel) {
        var render = RENDERERS[panel.id];
        var loading = panel.querySelector('[data-panel-loading]');
        var error = panel.querySelector('[data-panel-error]');
        var content = panel.querySelector('[data-panel-content]');

        function state(which) {
            // `hidden`, not d-none: the bmc macros use the attribute, and bmc-admin.css
            // enforces [hidden]{display:none!important} so nothing can out-specify it.
            loading.hidden = which !== 'loading';
            error.hidden   = which !== 'error';
            content.hidden = which !== 'content';
        }

        function run(force) {
            state('loading');
            busy(panel, true);
            load(panel.dataset.src, force).then(function (data) {
                render(content, data);
                stamp(panel.querySelector('[data-panel-stamp]'), data.generatedAt);
                state('content');
            }).catch(function (err) {
                panel.querySelector('[data-panel-error-text]').textContent = 'Could not load this panel (' + err.message + ').';
                state('error');
            }).finally(function () { busy(panel, false); });
        }

        panel.querySelectorAll('[data-panel-refresh]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                refreshCache(panel.dataset.panelKey).then(function () { run(true); });
            });
        });

        run(false);
    }

    /**
     * The strip's one button: clear every cached aggregate, then reload every panel.
     *
     * Lives here rather than in the page's own script because the panels it reloads are this
     * file's to drive — a second place that knew how to refresh them would be a second place
     * to keep in step.
     */
    function bindRefreshAll() {
        var btn = document.getElementById('dash-refresh-all');
        if (!btn) { return; }

        btn.addEventListener('click', function () {
            btn.disabled = true;
            refreshCache(null)
                .then(function () {
                    root.querySelectorAll('[data-panel-refresh],[data-pool-refresh]').forEach(function (b) {
                        if (b !== btn) { b.click(); }
                    });
                    if (window.BMC) { BMC.toast('STATS CACHE CLEARED · PANELS RELOADING'); }
                })
                .finally(function () { btn.disabled = false; });
        });
    }

    function bindPool() {
        var panel = root.querySelector('[data-pool-panel]');
        if (!panel) { return; }

        var tabs = Array.prototype.slice.call(panel.querySelectorAll('[data-pool-tab]'));
        var content = panel.querySelector('[data-pool-content]');
        var loading = panel.querySelector('[data-pool-loading]');
        var error = panel.querySelector('[data-pool-error]');
        var current = tabs[0];

        function state(which) {
            // `hidden`, not d-none: the bmc macros use the attribute, and bmc-admin.css
            // enforces [hidden]{display:none!important} so nothing can out-specify it.
            loading.hidden = which !== 'loading';
            error.hidden   = which !== 'error';
            content.hidden = which !== 'content';
        }

        function run(tab, force) {
            current = tab;
            state('loading');
            busy(panel, true);
            load(tab.dataset.src, force).then(function (data) {
                if (current !== tab) { return; }
                renderPool(content, data);
                stamp(panel.querySelector('[data-panel-stamp]'));
                state('content');
            }).catch(function (err) {
                if (current !== tab) { return; }
                panel.querySelector('[data-pool-error-text]').textContent = 'Could not load this breakdown (' + err.message + ').';
                state('error');
            }).finally(function () { busy(panel, false); });
        }

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { select(i); });
            // Arrow-key traversal, as a tablist is expected to support.
            tab.addEventListener('keydown', function (e) {
                var delta = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (!delta) { return; }
                e.preventDefault();
                var next = (i + delta + tabs.length) % tabs.length;
                tabs[next].focus();
                select(next);
            });
        });

        function select(i) {
            tabs.forEach(function (t) { t.setAttribute('aria-selected', 'false'); });
            tabs[i].setAttribute('aria-selected', 'true');
            run(tabs[i], false);
        }

        panel.querySelectorAll('[data-pool-refresh]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                refreshCache(current.dataset.panelKey).then(function () { run(current, true); });
            });
        });

        run(tabs[0], false);

        // Inventory KPI tiles read the same cached pool payloads.
        [['players', 'position'], ['staff', 'role'], ['agents', 'rating']].forEach(function (pair) {
            var tab = panel.querySelector('[data-pool-tab="' + pair[0] + '"]');
            if (!tab) { return; }
            load(tab.dataset.src, false)
                .then(function (data) { paintStockKpi(pair[0], data, pair[1]); })
                .catch(function () { /* the panel itself already reports the failure */ });
        });
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    var band = root.querySelector('[data-kpi-strip]');
    if (band) {
        load(band.dataset.src, false).then(paintKpis).catch(function () {
            band.querySelectorAll('[data-kpi-value]').forEach(function (n) {
                if (n.textContent === '—') { n.textContent = 'n/a'; }
            });
        });
    }

    root.querySelectorAll('[data-panel]').forEach(bindPanel);
    bindPool();
    bindRefreshAll();
})();
