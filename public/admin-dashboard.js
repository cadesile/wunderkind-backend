/* ══════════════════════════════════════════════════════════════════════════
   Build My Club — admin dashboard

   The page ships as a shell; each panel pulls its own JSON from
   AdminStatsController. Chart colours are read from the CSS custom properties
   in admin-theme.css so the charts track the theme rather than duplicating hex.
   ══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var root = document.querySelector('[data-dashboard]');
    if (!root) { return; }

    var REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Theme ────────────────────────────────────────────────────────────
    function cssVar(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }

    var PALETTE = (function () {
        var out = [];
        for (var i = 1; i <= 12; i++) { out.push(cssVar('--dash-chart-' + i, '#4e79a7')); }
        return out;
    })();
    var GRID = cssVar('--dash-grid', 'rgba(155,176,196,.16)');
    var TICK = cssVar('--dash-tick', '#9bb0c4');

    function colorAt(i) { return PALETTE[i % PALETTE.length]; }
    function alpha(hex, a) { return hex + a; }

    if (window.Chart) {
        Chart.defaults.font.family = "'Space Mono', monospace";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = TICK;
        Chart.defaults.animation = REDUCED ? false : { duration: 250 };
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.responsive = true;
    }

    var charts = [];
    function register(chart) { charts.push(chart); return chart; }
    function destroyIn(container) {
        charts = charts.filter(function (c) {
            if (c.canvas && container.contains(c.canvas)) { c.destroy(); return false; }
            return true;
        });
    }

    // ── Fetch (deduped: the KPI strip and the growth panel share a payload) ──
    var inflight = {};

    function load(url, force) {
        if (force) { delete inflight[url]; }
        if (!inflight[url]) {
            inflight[url] = fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
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

    // ── Small DOM helpers ────────────────────────────────────────────────
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function num(n) { return Number(n || 0).toLocaleString(); }

    function titleize(s) {
        return String(s).replace(/[_-]/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function stamp(node, iso) {
        if (!node) { return; }
        var d = iso ? new Date(iso) : new Date();
        node.textContent = 'updated ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function show(panel, which) {
        ['loading', 'error', 'content'].forEach(function (k) {
            var n = panel.querySelector('[data-' + panel.dataset.slot + '-' + k + ']');
            if (n) { n.classList.toggle('d-none', k !== which); }
        });
    }

    // ── Charts ───────────────────────────────────────────────────────────
    function barChart(canvas, facet, opts) {
        opts = opts || {};
        var labels = facet.map(function (r) { return r.key; });
        var data = facet.map(function (r) { return r.count; });

        return register(new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: labels.map(function (_, i) { return alpha(colorAt(opts.mono ? 0 : i), 'cc'); }),
                    borderColor: labels.map(function (_, i) { return colorAt(opts.mono ? 0 : i); }),
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: opts.horizontal ? 'y' : 'x',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0, color: TICK }, grid: { color: GRID } },
                    y: { beginAtZero: true, ticks: { precision: 0, color: TICK }, grid: { color: GRID } }
                }
            }
        }));
    }

    function doughnutChart(canvas, facet) {
        return register(new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: facet.map(function (r) { return r.key; }),
                datasets: [{
                    data: facet.map(function (r) { return r.count; }),
                    backgroundColor: facet.map(function (_, i) { return colorAt(i); }),
                    borderColor: cssVar('--bs-card-bg', '#1e2448'),
                    borderWidth: 2
                }]
            },
            options: { plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } } }
        }));
    }

    function sparkline(canvas, series, color) {
        return register(new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: series.map(function (_, i) { return i; }),
                datasets: [{
                    data: series,
                    borderColor: color,
                    backgroundColor: alpha(color, '22'),
                    borderWidth: 1.5,
                    fill: true,
                    pointRadius: 0,
                    tension: 0.3
                }]
            },
            options: {
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
            }
        }));
    }

    // ── KPI strip ────────────────────────────────────────────────────────
    var POOL_KEYS = { poolPlayers: 'players', poolStaff: 'staff', poolAgents: 'agents' };

    function paintKpis(growth) {
        var m = growth.metrics;

        Object.keys(m).forEach(function (metric) {
            var tile = root.querySelector('[data-kpi="' + metric + '"]');
            if (!tile) { return; }

            var data = m[metric];
            tile.querySelector('[data-kpi-value]').textContent = num(data.all);

            tile.querySelectorAll('[data-window]').forEach(function (node) {
                var v = data[node.dataset.window] || 0;
                node.textContent = v > 0 ? '+' + num(v) : '0';
                node.classList.toggle('is-zero', v === 0);
            });

            if (metric === 'users') {
                tile.querySelector('[data-kpi-sub]').textContent =
                    num(data.registered) + ' registered · ' + num(data.guest) + ' guest';
            }
            if (metric === 'activeClubs') {
                tile.querySelector('[data-kpi-sub]').textContent = 'clubs that have ever synced';
            }

            var spark = tile.querySelector('[data-kpi-spark]');
            var series = growth.trend.series[metric];
            if (spark && series) {
                destroyIn(spark.parentNode);
                sparkline(spark, series, metric === 'invalidSyncs' ? cssVar('--bs-danger', '#C44747') : colorAt(0));
            } else if (spark) {
                spark.parentNode.classList.add('d-none');
            }
        });
    }

    /** Pool totals live on the pool endpoints, so fill those tiles from there. */
    function paintPoolKpi(metric, total, sub) {
        var tile = root.querySelector('[data-kpi="' + metric + '"]');
        if (!tile) { return; }
        tile.querySelector('[data-kpi-value]').textContent = num(total);
        if (sub) { tile.querySelector('[data-kpi-sub]').textContent = sub; }
        var deltas = tile.querySelector('[data-kpi-deltas]');
        if (deltas) { deltas.classList.add('d-none'); }
        var spark = tile.querySelector('.kpi-spark');
        if (spark) { spark.classList.add('d-none'); }
    }

    // ── Panel renderers ──────────────────────────────────────────────────
    function renderGrowth(container, data) {
        destroyIn(container);
        container.textContent = '';

        var wrap = el('div', 'dash-chart');
        wrap.style.height = '260px';
        var canvas = document.createElement('canvas');
        wrap.appendChild(canvas);
        container.appendChild(wrap);

        // Series are differentiated by dash pattern as well as colour, so the
        // chart still reads without colour perception.
        var styles = [[], [6, 4], [2, 3]];
        var keys = ['users', 'clubs', 'syncs'];

        register(new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: data.trend.days.map(function (d) { return d.slice(5); }),
                datasets: keys.map(function (k, i) {
                    return {
                        label: titleize(k),
                        data: data.trend.series[k],
                        borderColor: colorAt(i),
                        backgroundColor: alpha(colorAt(i), '22'),
                        borderDash: styles[i],
                        borderWidth: 2,
                        pointRadius: 0,
                        pointHitRadius: 12,
                        tension: 0.25,
                        fill: false
                    };
                })
            },
            options: {
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', labels: { boxWidth: 24, usePointStyle: false } } },
                scales: {
                    x: { ticks: { color: TICK, maxTicksLimit: 10 }, grid: { color: GRID } },
                    y: { beginAtZero: true, ticks: { precision: 0, color: TICK }, grid: { color: GRID } }
                }
            }
        }));
    }

    function renderLeaderboards(container, data) {
        container.textContent = '';

        var tabs = el('div', 'dash-tabs');
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'Leaderboard category');

        var region = el('div', 'dash-scroll');
        region.setAttribute('role', 'tabpanel');

        function paint(board) {
            region.textContent = '';

            if (!board.entries.length) {
                region.appendChild(el('p', 'dash-empty', 'No ranked clubs yet — boards only include clubs that have concluded a season.'));
                return;
            }

            var table = el('table', 'dash-table');
            var thead = el('thead');
            var hr = el('tr');
            ['#', 'Club', 'Score', 'Label'].forEach(function (h, i) {
                var th = el('th', i === 2 ? 'num' : null, h);
                hr.appendChild(th);
            });
            thead.appendChild(hr);
            table.appendChild(thead);

            var tbody = el('tbody');
            board.entries.forEach(function (row) {
                var tr = el('tr');
                tr.appendChild(el('td', 'num', row.rank));
                tr.appendChild(el('td', null, row.clubName));
                tr.appendChild(el('td', 'num', num(row.score)));
                tr.appendChild(el('td', null, row.displayLabel || '—'));
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            region.appendChild(table);

            region.appendChild(el('p', 'dash-empty', board.total + ' ranked club' + (board.total === 1 ? '' : 's') + ' in this board.'));
        }

        data.boards.forEach(function (board, i) {
            var btn = el('button', 'dash-tab', board.label);
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
            btn.addEventListener('click', function () {
                tabs.querySelectorAll('.dash-tab').forEach(function (b) { b.setAttribute('aria-selected', 'false'); });
                btn.setAttribute('aria-selected', 'true');
                paint(board);
            });
            tabs.appendChild(btn);
            if (i === 0) { paint(board); }
        });

        container.appendChild(tabs);
        container.appendChild(region);
    }

    // ── Pool breakdown ───────────────────────────────────────────────────
    var CHART_HINTS = { position: 'doughnut', role: 'doughnut', agent: 'doughnut', tier: 'doughnut' };

    function renderPool(container, data) {
        destroyIn(container);
        container.textContent = '';

        if (!data.total) {
            container.appendChild(el('p', 'dash-empty', 'Nothing in this pool yet.'));
            return;
        }

        var head = el('p', 'dash-empty');
        head.textContent = num(data.total) + ' ' + (data.label || '').toLowerCase() + ' in the pool.';
        if (data.unagentedPlayers !== undefined) {
            head.textContent += ' ' + num(data.unagentedPlayers) + ' pool players have no agent.';
        }
        if (data.summary) {
            head.textContent += ' ' + num(data.summary.leagues) + ' leagues · '
                + num(data.summary.cachedWorldPacks) + ' cached world packs ('
                + num(data.summary.staleWorldPacks) + ' stale).';
        }
        container.appendChild(head);

        // Overview facets
        var grid = el('div', 'facet-grid');
        Object.keys(data.facets).forEach(function (name) {
            var facet = data.facets[name];
            if (!facet.length) { return; }

            var cell = el('div', 'facet-cell');
            cell.appendChild(el('h6', null, titleize(name)));

            var holder = el('div', 'facet-canvas dash-chart');
            var canvas = document.createElement('canvas');
            holder.appendChild(canvas);
            cell.appendChild(holder);
            grid.appendChild(cell);

            // Deferred so the canvas has laid out before Chart.js measures it.
            requestAnimationFrame(function () {
                if (CHART_HINTS[name] === 'doughnut' && facet.length <= 8) {
                    doughnutChart(canvas, facet);
                } else if (facet.length > 8) {
                    barChart(canvas, facet.slice(0, 15), { horizontal: true });
                } else {
                    barChart(canvas, facet, { mono: true });
                }
            });
        });
        container.appendChild(grid);

        container.appendChild(renderNested(data.nested, data.total));
    }

    /**
     * Collapsible tree table — the primary drill-down view. Chosen over a
     * treemap/sunburst deliberately: exact counts stay visible, and every row
     * is reachable by keyboard.
     */
    function renderNested(nested, total) {
        var wrap = el('div', 'dash-scroll');
        var table = el('table', 'dash-table');

        var thead = el('thead');
        var hr = el('tr');
        [titleize(nested.dimension), 'Count', 'Share', ''].forEach(function (h, i) {
            hr.appendChild(el('th', i === 1 ? 'num' : null, h));
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        var tbody = el('tbody');

        nested.rows.forEach(function (row, i) {
            var pct = total ? (row.count / total) * 100 : 0;
            var childId = 'nested-' + nested.dimension + '-' + i;

            var tr = el('tr');

            var tdKey = el('td');
            var btn = el('button', 'dash-toggle');
            btn.type = 'button';
            btn.setAttribute('aria-expanded', 'false');
            btn.setAttribute('aria-controls', childId);
            btn.appendChild(el('i', 'fa fa-chevron-right caret'));
            btn.appendChild(el('span', null, row.key));
            tdKey.appendChild(btn);
            tr.appendChild(tdKey);

            tr.appendChild(el('td', 'num', num(row.count)));

            var tdShare = el('td');
            tdShare.appendChild(el('span', null, pct.toFixed(1) + '%'));
            tr.appendChild(tdShare);

            var tdMeter = el('td');
            var meter = el('span', 'dash-meter');
            var fill = el('i');
            fill.style.width = Math.max(pct, 1).toFixed(2) + '%';
            meter.appendChild(fill);
            tdMeter.appendChild(meter);
            tr.appendChild(tdMeter);

            tbody.appendChild(tr);

            var childTr = el('tr', 'dash-children d-none');
            childTr.id = childId;
            var childTd = el('td');
            childTd.colSpan = 4;

            var cg = el('div', 'child-grid');
            nested.children.forEach(function (dim) {
                var col = el('div');
                col.appendChild(el('h6', null, titleize(dim)));
                var ul = el('ul');
                (row.children[dim] || []).forEach(function (c) {
                    var li = el('li');
                    li.appendChild(el('span', 'k', c.key));
                    li.appendChild(el('span', 'v', num(c.count)));
                    ul.appendChild(li);
                });
                if (!ul.children.length) { ul.appendChild(el('li', null, '—')); }
                col.appendChild(ul);
                cg.appendChild(col);
            });

            childTd.appendChild(cg);
            childTr.appendChild(childTd);
            tbody.appendChild(childTr);

            btn.addEventListener('click', function () {
                var open = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                childTr.classList.toggle('d-none', open);
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
        panel.dataset.slot = 'panel';
        var render = RENDERERS[panel.id];

        function run(force) {
            show(panel, 'loading');
            load(panel.dataset.src, force).then(function (data) {
                var content = panel.querySelector('[data-panel-content]');
                render(content, data);
                stamp(panel.querySelector('[data-panel-stamp]'), data.generatedAt);
                show(panel, 'content');
            }).catch(function (err) {
                panel.querySelector('[data-panel-error-text]').textContent = 'Could not load this panel (' + err.message + ').';
                show(panel, 'error');
            });
        }

        panel.querySelectorAll('[data-panel-refresh]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                refreshCache(panel.dataset.panelKey).then(function () { run(true); });
            });
        });

        run(false);
    }

    function bindPool() {
        var panel = root.querySelector('[data-pool-panel]');
        if (!panel) { return; }

        var tabs = panel.querySelectorAll('[data-pool-tab]');
        var content = panel.querySelector('[data-pool-content]');
        var loading = panel.querySelector('[data-pool-loading]');
        var error = panel.querySelector('[data-pool-error]');
        var current = tabs[0];

        function state(which) {
            loading.classList.toggle('d-none', which !== 'loading');
            error.classList.toggle('d-none', which !== 'error');
            content.classList.toggle('d-none', which !== 'content');
        }

        function run(tab, force) {
            current = tab;
            state('loading');
            load(tab.dataset.src, force).then(function (data) {
                if (current !== tab) { return; }
                renderPool(content, data);
                stamp(panel.querySelector('[data-panel-stamp]'));
                state('content');
            }).catch(function (err) {
                if (current !== tab) { return; }
                panel.querySelector('[data-pool-error-text]').textContent = 'Could not load this breakdown (' + err.message + ').';
                state('error');
            });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.setAttribute('aria-selected', 'false'); });
                tab.setAttribute('aria-selected', 'true');
                run(tab, false);
            });
        });

        panel.querySelectorAll('[data-pool-refresh]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                refreshCache(current.dataset.panelKey).then(function () { run(current, true); });
            });
        });

        run(tabs[0], false);

        // Fill the pool KPI tiles without a second render pass.
        Object.keys(POOL_KEYS).forEach(function (metric) {
            var entity = POOL_KEYS[metric];
            var tab = panel.querySelector('[data-pool-tab="' + entity + '"]');
            if (!tab) { return; }
            load(tab.dataset.src, false).then(function (data) {
                if (metric === 'poolAgents') {
                    var scoutTab = panel.querySelector('[data-pool-tab="scouts"]');
                    load(scoutTab.dataset.src, false).then(function (scouts) {
                        paintPoolKpi(metric, scouts.total + data.total, num(scouts.total) + ' scouts · ' + num(data.total) + ' agents');
                    });
                } else {
                    paintPoolKpi(metric, data.total);
                }
            }).catch(function () { /* the panel itself already reports the failure */ });
        });
    }

    // ── Boot ─────────────────────────────────────────────────────────────
    var strip = root.querySelector('[data-kpi-strip]');
    if (strip) {
        load(strip.dataset.src, false).then(paintKpis).catch(function () {
            strip.querySelectorAll('[data-kpi-value]').forEach(function (n) {
                if (n.textContent === '—') { n.textContent = 'n/a'; }
            });
        });
    }

    root.querySelectorAll('[data-panel]').forEach(bindPanel);
    bindPool();
})();
