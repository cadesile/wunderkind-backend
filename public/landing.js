/* ══════════════════════════════════════════════════════════════════════
   Build My Club — landing page behaviour

   Extracted from the former inline <script> blocks in public/index.html.
   Plain ES5-compatible DOM code, no dependencies, no build step.
   ══════════════════════════════════════════════════════════════════════ */

// ── Phone carousel ──────────────────────────────────────────────────────────
(function () {
    var slides = document.querySelectorAll('.phone-slide');
    var dots   = document.querySelectorAll('.phone-dot');
    var current = 0;
    var timer;

    function goTo(n) {
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (n + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
    }

    function next() { goTo(current + 1); }

    function start() { timer = setInterval(next, 3200); }
    function stop()  { clearInterval(timer); }

    dots.forEach(function (dot, i) {
        dot.addEventListener('click', function () { stop(); goTo(i); start(); });
        dot.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { stop(); goTo(i); start(); }
        });
    });

    start();
}());

// ── Download links ──────────────────────────────────────────────────────────
(function () {
    fetch('/api/app-links')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var androidBtn     = document.getElementById('android-download-btn');
            var iosBtn         = document.getElementById('ios-download-btn');
            var heroGenericBtn = document.getElementById('hero-download-btn');
            var heroAndroidBtn = document.getElementById('hero-android-btn');
            var heroIosBtn     = document.getElementById('hero-ios-btn');
            var note           = document.getElementById('download-note');

            if (data.android) {
                androidBtn.href = data.android;
                androidBtn.style.display = '';
                heroAndroidBtn.href = data.android;
                heroAndroidBtn.style.display = '';
            }

            if (data.ios) {
                iosBtn.href = data.ios;
                iosBtn.style.display = '';
                heroIosBtn.href = data.ios;
                heroIosBtn.style.display = '';
            }

            if (data.android || data.ios) {
                heroGenericBtn.style.display = 'none';
            }

            if (data.facebook) {
                document.getElementById('nav-facebook-link').href = data.facebook;
                document.getElementById('nav-facebook-link').style.display = '';
                document.getElementById('footer-facebook-link').href = data.facebook;
                document.getElementById('footer-facebook-link').style.display = '';
            }

            if (data.x) {
                document.getElementById('nav-x-link').href = data.x;
                document.getElementById('nav-x-link').style.display = '';
                document.getElementById('footer-x-link').href = data.x;
                document.getElementById('footer-x-link').style.display = '';
            }

            var missing = [];
            if (!data.android) missing.push('Android');
            if (!data.ios) missing.push('iOS');
            if (missing.length) {
                note.textContent = missing.join(' & ') + ' version' + (missing.length > 1 ? 's' : '') + ' not yet available. Free to play. Works fully offline. No ads in gameplay.';
            } else {
                note.textContent = 'Free to play. Works fully offline. No ads in gameplay.';
            }


        })
        .catch(function () {
            document.getElementById('download-note').textContent = 'Download links temporarily unavailable. Please check back soon.';
        });
}());

// ── Privacy modal ────────────────────────────────────────────────────────────
(function () {
    var overlay = document.getElementById('privacy-overlay');
    var closeBtn = document.getElementById('privacy-close');

    function openPrivacy() {
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    function closePrivacy() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        if (window.location.hash === '#privacy') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }

    function handleHash() {
        if (window.location.hash === '#privacy') {
            openPrivacy();
        } else {
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }
    }

    closeBtn.addEventListener('click', closePrivacy);

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closePrivacy();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closePrivacy();
    });

    window.addEventListener('hashchange', handleHash);
    handleHash();
}());

// ── Beta request modal ───────────────────────────────────────────────────────
function openBetaModal() {
    var overlay = document.getElementById('beta-overlay');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('beta-email').focus();
}

(function () {
    var overlay       = document.getElementById('beta-overlay');
    var closeBtn      = document.getElementById('beta-close');
    var stepEmail     = document.getElementById('beta-step-email');
    var stepCode      = document.getElementById('beta-step-code');
    var stepSuccess   = document.getElementById('beta-step-success');
    var formEmail     = document.getElementById('beta-form-email');
    var formCode      = document.getElementById('beta-form-code');
    var emailEl       = document.getElementById('beta-email');
    var codeEl        = document.getElementById('beta-code');
    var submitEmail   = document.getElementById('beta-submit-email');
    var submitCode    = document.getElementById('beta-submit-code');
    var msgEmail      = document.getElementById('beta-msg-email');
    var msgCode       = document.getElementById('beta-msg-code');
    var codeHint      = document.getElementById('beta-code-hint');
    var resendBtn     = document.getElementById('beta-resend');
    var pendingEmail  = '';

    function resetModal() {
        stepEmail.style.display   = '';
        stepCode.style.display    = 'none';
        stepSuccess.style.display = 'none';
        formEmail.reset();
        formCode.reset();
        msgEmail.className = 'beta-msg';
        msgEmail.textContent = '';
        msgCode.className = 'beta-msg';
        msgCode.textContent = '';
        submitEmail.disabled = false;
        submitEmail.textContent = 'Send Code';
        submitCode.disabled = false;
        submitCode.textContent = 'Verify Code';
        pendingEmail = '';
    }

    function closeBeta() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        resetModal();
    }

    function showMsg(el, text, type) {
        el.textContent = text;
        el.className = 'beta-msg ' + type;
    }

    closeBtn.addEventListener('click', closeBeta);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeBeta(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeBeta();
    });

    function sendCode(email, onSuccess, onError, onNetwork) {
        var body = new FormData();
        body.append('email', email);
        fetch('/api/beta-request', { method: 'POST', body: body })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) { res.ok ? onSuccess(res.data) : onError(res.data); })
            .catch(onNetwork);
    }

    // ── Step 1: Submit email ──────────────────────────────────────────────────
    formEmail.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = emailEl.value.trim();
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showMsg(msgEmail, 'Please enter a valid email address.', 'error');
            return;
        }

        submitEmail.disabled = true;
        submitEmail.textContent = 'Sending…';
        msgEmail.className = 'beta-msg';

        sendCode(
            email,
            function () {
                pendingEmail = email;
                codeHint.textContent = 'Enter the 6-digit code sent to ' + email + '.';
                stepEmail.style.display = 'none';
                stepCode.style.display  = '';
                codeEl.focus();
            },
            function (data) {
                showMsg(msgEmail, data.error || 'Something went wrong.', 'error');
                submitEmail.disabled = false;
                submitEmail.textContent = 'Send Code';
            },
            function () {
                showMsg(msgEmail, 'Network error. Please try again.', 'error');
                submitEmail.disabled = false;
                submitEmail.textContent = 'Send Code';
            }
        );
    });

    // ── Step 2: Verify code ───────────────────────────────────────────────────
    formCode.addEventListener('submit', function (e) {
        e.preventDefault();
        var code = codeEl.value.trim();
        if (!code || !/^\d{6}$/.test(code)) {
            showMsg(msgCode, 'Please enter the 6-digit code.', 'error');
            return;
        }

        submitCode.disabled = true;
        submitCode.textContent = 'Verifying…';
        msgCode.className = 'beta-msg';

        var body = new FormData();
        body.append('email', pendingEmail);
        body.append('code', code);

        fetch('/api/beta-request/verify', { method: 'POST', body: body })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (res.ok && res.data.success) {
                    stepCode.style.display    = 'none';
                    stepSuccess.style.display = '';
                } else {
                    showMsg(msgCode, res.data.error || 'Something went wrong.', 'error');
                    submitCode.disabled = false;
                    submitCode.textContent = 'Verify Code';
                }
            })
            .catch(function () {
                showMsg(msgCode, 'Network error. Please try again.', 'error');
                submitCode.disabled = false;
                submitCode.textContent = 'Verify Code';
            });
    });

    // ── Resend ────────────────────────────────────────────────────────────────
    resendBtn.addEventListener('click', function () {
        if (!pendingEmail) return;
        resendBtn.disabled = true;
        resendBtn.textContent = 'Sending…';
        msgCode.className = 'beta-msg';
        formCode.reset();

        sendCode(
            pendingEmail,
            function () {
                showMsg(msgCode, 'New code sent!', 'success');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
                codeEl.focus();
            },
            function (data) {
                showMsg(msgCode, data.error || 'Could not resend. Please try again.', 'error');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
            },
            function () {
                showMsg(msgCode, 'Network error. Please try again.', 'error');
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend code';
            }
        );
    });
}());

(function () {
    var ENDPOINTS = {
        transfers:   '/api/stats/most-transfers',
        development: '/api/stats/most-development',
        seasons:     '/api/stats/most-seasons',
        trophies:    '/api/stats/most-trophies',
    };

    var tabs = document.querySelectorAll('#cs-period-tabs .period-tab');
    if (!tabs.length) return;

    function renderList(listEl, results) {
        listEl.innerHTML = '';
        if (!results.length) {
            listEl.innerHTML = '<li class="lb-empty">No data yet — be the first</li>';
            return;
        }
        results.forEach(function (row) {
            var li = document.createElement('li');
            li.className = 'lb-item';
            var rank = String(row.rank).padStart(2, '0');
            li.innerHTML =
                '<span class="lb-rank">' + rank + '</span>' +
                '<span class="lb-name"></span>' +
                '<span class="lb-value"></span>';
            li.querySelector('.lb-name').textContent = row.clubName;
            li.querySelector('.lb-value').textContent = row.value;
            listEl.appendChild(li);
        });
    }

    function sumValues(results) {
        return results.reduce(function (total, row) { return total + row.value; }, 0);
    }

    function loadPeriod(period) {
        Object.keys(ENDPOINTS).forEach(function (key) {
            var listEl = document.getElementById('cs-list-' + key);
            var statEl = document.getElementById('cs-stat-' + key);
            listEl.innerHTML = '<li class="lb-loading">Loading…</li>';

            fetch(ENDPOINTS[key] + '?period=' + encodeURIComponent(period) + '&limit=10')
                .then(function (res) {
                    if (!res.ok) throw new Error('bad response');
                    return res.json();
                })
                .then(function (data) {
                    renderList(listEl, data.results);
                    if (statEl) statEl.textContent = sumValues(data.results);
                })
                .catch(function () {
                    listEl.innerHTML = '<li class="lb-error">Unavailable</li>';
                    if (statEl) statEl.textContent = '--';
                });
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            loadPeriod(tab.dataset.period);
        });
    });

    loadPeriod('week');
}());

(function () {
    var tabsEl = document.getElementById('lbg-tabs');
    if (!tabsEl) return;

    var listEl  = document.getElementById('lbg-list');
    var titleEl = document.getElementById('lbg-panel-title');
    var subEl   = document.getElementById('lbg-panel-sub');
    var tabs    = Array.prototype.slice.call(tabsEl.querySelectorAll('.lbg-tab'));

    var CATEGORIES = {
        hall_of_fame:     { label: 'Hall of Fame',     unit: 'HoF points',        format: 'int' },
        club_reputation:  { label: 'Reputation',       unit: 'reputation points', format: 'int' },
        career_earnings:  { label: 'Career Earnings',  unit: 'lifetime earnings', format: 'currency' },
        golden_boot:      { label: 'Golden Boot',      unit: 'goals · top scorer',   format: 'int' },
        playmaker:        { label: 'Playmaker',        unit: 'assists · top provider', format: 'int' },
        empire_index:     { label: 'Empire Index',     unit: 'facility levels',   format: 'int' },
        fanatics:         { label: 'Fanatics',         unit: 'season attendance', format: 'int' },
        club_goals:       { label: 'Club Goals',       unit: 'goals · whole squad',   format: 'int' },
        club_assists:     { label: 'Club Assists',     unit: 'assists · whole squad', format: 'int' },
        iron_man:         { label: 'Iron Man',         unit: 'appearances · most capped', format: 'int' },
        transfer_record:  { label: 'Transfer Record',  unit: 'biggest fee received', format: 'currency' },
        transfer_spend:   { label: 'Transfer Spend',   unit: 'biggest fee paid',     format: 'currency' },
    };

    var PAGE_SIZE  = 10;
    var loaded     = {};   // category -> true once fetched at least once
    var activeReq  = 0;    // guards against out-of-order responses when switching tabs fast
    var hasBooted  = false;

    function formatValue(value, format) {
        if (format === 'currency') {
            return '£' + Math.round(value / 100).toLocaleString('en-GB');
        }
        return Number(value).toLocaleString('en-GB');
    }

    function skeletonRows() {
        var html = '';
        for (var i = 0; i < 3; i++) {
            html += '<li class="lbg-skel-row">' +
                '<span class="lbg-skel lbg-skel-rank"></span>' +
                '<span class="lbg-skel lbg-skel-main"></span>' +
                '<span class="lbg-skel lbg-skel-value"></span></li>';
        }
        return html;
    }

    function renderEntries(entries, format, category) {
        listEl.innerHTML = '';
        if (!entries.length) {
            listEl.innerHTML = '<li class="lbg-empty">No clubs ranked yet — be the first</li>';
            return;
        }
        entries.forEach(function (entry) {
            var li = document.createElement('li');
            li.className = 'lbg-row rank-' + entry.rank;
            li.innerHTML =
                '<span class="lbg-row-rank">' + String(entry.rank).padStart(2, '0') + '</span>' +
                '<span class="lbg-row-main">' +
                    '<span class="lbg-row-name"></span>' +
                    (entry.displayLabel ? '<span class="lbg-row-tag"></span>' : '') +
                '</span>' +
                '<span class="lbg-row-value"></span>';
            li.querySelector('.lbg-row-name').textContent = entry.clubName;
            if (entry.displayLabel) {
                li.querySelector('.lbg-row-tag').textContent = entry.displayLabel;
            }
            if(category != 'hall_of_fame'){
                li.querySelector('.lbg-row-value').textContent = formatValue(entry.score, format);   
            }
            listEl.appendChild(li);
        });
    }

    function loadCategory(category) {
        var meta = CATEGORIES[category];
        if (!meta) return;

        titleEl.textContent = meta.label.toUpperCase();
        subEl.textContent   = 'all-time · ' + meta.unit;
        listEl.innerHTML    = skeletonRows();

        var requestId = ++activeReq;

        fetch('/api/leaderboard/' + encodeURIComponent(category) + '?period=all-time&pageSize=' + PAGE_SIZE)
            .then(function (res) {
                if (!res.ok) throw new Error('bad response');
                return res.json();
            })
            .then(function (data) {
                if (requestId !== activeReq) return; // a newer tab switch superseded this request
                loaded[category] = true;
                renderEntries(data.entries || [], meta.format, category);
            })
            .catch(function () {
                if (requestId !== activeReq) return;
                listEl.innerHTML = '<li class="lbg-error">Leaderboard unavailable — try again shortly</li>';
            });
    }

    function activateTab(tab, focusTab) {
        tabs.forEach(function (t) {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
            t.setAttribute('tabindex', '-1');
        });
        tab.classList.add('active');
        tab.setAttribute('aria-selected', 'true');
        tab.setAttribute('tabindex', '0');
        if (focusTab) tab.focus();
        loadCategory(tab.dataset.category);
    }

    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { activateTab(tab, false); });
        tab.addEventListener('keydown', function (e) {
            var targetIndex = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') targetIndex = (i + 1) % tabs.length;
            if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   targetIndex = (i - 1 + tabs.length) % tabs.length;
            if (targetIndex !== null) {
                e.preventDefault();
                activateTab(tabs[targetIndex], true);
            }
        });
    });

    // Lazy-load: only fetch once the section actually scrolls into view.
    var section = document.getElementById('leaderboards');
    if ('IntersectionObserver' in window && section) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && !hasBooted) {
                    hasBooted = true;
                    loadCategory('hall_of_fame');
                    observer.disconnect();
                }
            });
        }, { rootMargin: '200px' });
        observer.observe(section);
    } else {
        loadCategory('hall_of_fame');
    }
}());

/* ══════════════════════════════════════════════════════════════════════
   YouTube click-to-play facade.

   The poster is a plain <img> until the visitor asks to watch. Embedding a
   live iframe on page load would pull ~1MB of Google JS and set third-party
   cookies on every visit, on a page that has no consent banner — so the
   player is only constructed on click, and against youtube-nocookie.com.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var facade = document.querySelector('[data-video-id]');
    if (!facade) { return; }

    var button = facade.querySelector('.video-play');
    if (!button) { return; }

    button.addEventListener('click', function () {
        var id = facade.getAttribute('data-video-id');
        var title = facade.getAttribute('data-video-title') || 'Build My Club';

        var iframe = document.createElement('iframe');
        iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0';
        iframe.title = title;
        iframe.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

        button.replaceWith(iframe);
    });
})();
