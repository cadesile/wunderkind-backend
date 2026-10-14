/* ══════════════════════════════════════════════════════════════════════
   Build My Club — landing page behaviour

   Extracted from the former inline <script> blocks in public/index.html.
   Plain ES5-compatible DOM code, no dependencies, no build step.
   ══════════════════════════════════════════════════════════════════════ */

// ── Club kit/badge rendering (shared by the ticker, incident modal, and leaderboard) ──
//
// Read-only display, no editing controls — reuses composeKitSvg() from
// public/assets/kit-compositor.js verbatim (must load before this file, see
// base.html.twig), the same pixel-sprite renderer the admin kit-identity widget
// uses. clubBadge is always {home: ?object, away: ?object, badge: ?object} — home
// and badge configs are merged into one object since composeKitSvg() only reads
// the keys relevant to the type ('kit' or 'badge') it's asked to draw; a null/
// missing config still renders composeKitSvg's own built-in defaults, so every
// club shows *something* even before it customizes its identity.
function clubBadgeIconsHtml(clubBadge) {
    if (typeof composeKitSvg !== 'function') return '';

    var cfg = {};
    var home = (clubBadge && clubBadge.home) || {};
    var badge = (clubBadge && clubBadge.badge) || {};
    var key;
    for (key in home) if (Object.prototype.hasOwnProperty.call(home, key)) cfg[key] = home[key];
    for (key in badge) if (Object.prototype.hasOwnProperty.call(badge, key)) cfg[key] = badge[key];

    return composeKitSvg(cfg, 'badge', null, 1.4) + composeKitSvg(cfg, 'kit', 'small', 1.4);
}

// ── Club nationality chip ──
//
// A vendored flag SVG from /assets/flags/ (MIT flag-icons — see that directory's
// README), keyed by the lower-cased App\Enum\Country code. Not an emoji flag:
// several of these codes have no regional-indicator equivalent at all, and
// England in particular needs a subdivision tag sequence that Windows and most
// Android builds don't draw. Shared by the community-stats cards and the global
// leaderboard; the owner spotlight renders the same flags from Twig.
//
// The 2-letter code remains the alt/title text, so a screen reader gets exactly
// what it got when the chip WAS the code, and so a flag is never the only carrier
// of which country it is — several are near-identical at 18px.
//
// onerror falls back to the old text chip rather than leaving a broken image:
// CountryFlagAssetTest guarantees an SVG for every Country case, but Club.country
// is a free string column, so a value outside the enum can still reach here.
//
// Returns '' for a club with no country so callers can concatenate unconditionally.
function clubCountryChipHtml(country) {
    if (!country) return '';
    var code = String(country).toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
    if (!code) return '';

    return '<img class="club-flag" src="/assets/flags/' + code.toLowerCase() + '.svg"' +
        ' width="18" height="13" loading="lazy" decoding="async"' +
        ' alt="' + code + '" title="' + code + '"' +
        ' onerror="this.outerHTML=\'<span class=&quot;club-flag club-flag-code&quot;>' + code + '</span>\'">';
}

// ── Club Spotlight (kit/badge via composeKitSvg, owner avatar via
//    composePlayerSpriteSvg, stadium via composeStadiumSvg) ──
(function () {
    var card = document.querySelector('.spotlight-card');
    if (!card) return;

    var badgeSlot = card.querySelector('.spotlight-badge-slot');
    if (badgeSlot) {
        var rawBadge = card.getAttribute('data-club-badge');
        var parsedBadge = null;
        try { parsedBadge = rawBadge ? JSON.parse(rawBadge) : null; } catch (e) { parsedBadge = null; }
        badgeSlot.innerHTML = clubBadgeIconsHtml(parsedBadge);
    }

    var ownerSlot = card.querySelector('.spotlight-owner-slot');
    if (ownerSlot && typeof composePlayerSpriteSvg === 'function') {
        var rawAppearance = card.getAttribute('data-owner-appearance');
        var parsedAppearance = null;
        try { parsedAppearance = rawAppearance ? JSON.parse(rawAppearance) : null; } catch (e) { parsedAppearance = null; }
        // Owner avatars always use the 'staff' body shape — see CLAUDE.md's
        // Owner Identity section (AppearanceRole::OWNER falls into the
        // existing non-player branching server-side).
        ownerSlot.innerHTML = composePlayerSpriteSvg(parsedAppearance || {}, 'staff', 'large', 3);
    }

    var stadiumSlot = card.querySelector('.spotlight-stadium-slot');
    if (stadiumSlot && typeof composeStadiumSvg === 'function') {
        var rawStadium = card.getAttribute('data-stadium-config');
        var parsedStadium = null;
        try { parsedStadium = rawStadium ? JSON.parse(rawStadium) : null; } catch (e) { parsedStadium = null; }
        stadiumSlot.innerHTML = composeStadiumSvg(parsedStadium || {}, { rotation: 0, time: 'day' }, 2);
    }

    // Only present when the top performer has ever reported a sync v2
    // appearanceConfig — most haven't yet (see _spotlight.html.twig).
    var performerSlot = card.querySelector('.spotlight-performer-slot');
    if (performerSlot && typeof composePlayerSpriteSvg === 'function') {
        var rawPerformer = card.getAttribute('data-performer-appearance');
        var parsedPerformer = null;
        try { parsedPerformer = rawPerformer ? JSON.parse(rawPerformer) : null; } catch (e) { parsedPerformer = null; }
        if (parsedPerformer) {
            performerSlot.innerHTML = composePlayerSpriteSvg(parsedPerformer, 'player', 'large', 3);
        }
    }
})();

// ── Owner Spotlight (owner avatars + per-club badges) ──
//
// Same renderers as the Club Spotlight above, just over a list. Every club row
// gets at least a badge (clubBadgeIconsHtml falls back to composeKitSvg's own
// defaults for a club that has never customized its identity), while the owner
// avatar is only drawn when that owner has actually saved one — owner identity is
// newer than most accounts, so a null appearance is the common case, and an empty
// slot is better than a default face attributed to a named person.
(function () {
    var rows = document.querySelectorAll('.ospot-row');
    if (!rows.length) return;

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];

        var avatarSlot = row.querySelector('.ospot-avatar-slot');
        if (avatarSlot && typeof composePlayerSpriteSvg === 'function') {
            var rawOwner = row.getAttribute('data-owner-appearance');
            var owner = null;
            try { owner = rawOwner ? JSON.parse(rawOwner) : null; } catch (e) { owner = null; }
            if (owner) {
                // 'staff' body shape — AppearanceRole::OWNER falls into the
                // non-player branching server-side (see CLAUDE.md, Owner Identity).
                avatarSlot.innerHTML = composePlayerSpriteSvg(owner, 'staff', 'large', 2);
            }
        }

        var clubEls = row.querySelectorAll('.ospot-club');
        for (var j = 0; j < clubEls.length; j++) {
            var badgeSlot = clubEls[j].querySelector('.ospot-club-badge');
            if (!badgeSlot) continue;
            var rawClubBadge = clubEls[j].getAttribute('data-club-badge');
            var clubBadge = null;
            try { clubBadge = rawClubBadge ? JSON.parse(rawClubBadge) : null; } catch (e) { clubBadge = null; }
            badgeSlot.innerHTML = clubBadgeIconsHtml(clubBadge);
        }
    }
}());

// ── Boardroom Incident & Consequence Feed (ticker + incident inspector modal) ──
(function () {
    var items = document.querySelectorAll('.terminal-feed-item');
    if (!items.length) return;

    var current = 0;
    var rotateTimer = null;

    function next() {
        items[current].classList.remove('active');
        current = (current + 1) % items.length;
        items[current].classList.add('active');
    }

    function startRotation() {
        if (rotateTimer !== null) return;
        rotateTimer = setInterval(next, 4500);
    }

    function stopRotation() {
        if (rotateTimer === null) return;
        clearInterval(rotateTimer);
        rotateTimer = null;
    }

    startRotation();

    // Render each ticker item's kit+badge once up front — cheap (≤18 items) and
    // avoids re-composing the same SVG every time the rotation reveals a row.
    for (var badgeIdx = 0; badgeIdx < items.length; badgeIdx++) {
        var badgeSlot = items[badgeIdx].querySelector('.terminal-feed-badge');
        if (!badgeSlot) continue;
        var rawBadge = items[badgeIdx].getAttribute('data-club-badge');
        var parsedBadge = null;
        try { parsedBadge = rawBadge ? JSON.parse(rawBadge) : null; } catch (e) { parsedBadge = null; }
        badgeSlot.innerHTML = clubBadgeIconsHtml(parsedBadge);
    }

    // ── Incident inspector modal ──
    var overlay      = document.getElementById('incident-overlay');
    var closeBtn      = document.getElementById('incident-close');
    var crestEl       = document.getElementById('incident-crest');
    var categoryEl    = document.getElementById('incident-category');
    var clubEl        = document.getElementById('incident-club');
    var timestampEl   = document.getElementById('incident-timestamp');
    var amountRowEl   = document.getElementById('incident-amount-row');
    var amountEl      = document.getElementById('incident-amount');
    var detailEl      = document.getElementById('incident-detail');
    var metaExtraEl   = document.getElementById('incident-meta-extra');
    var playerEl      = document.getElementById('incident-player');
    var playerAvatar  = document.getElementById('incident-player-avatar');
    var playerNameEl  = document.getElementById('incident-player-name');
    var playerSubEl   = document.getElementById('incident-player-sub');
    var playerRateEl  = document.getElementById('incident-player-rating');
    var fixturesEl    = document.getElementById('incident-fixtures');
    var fixtureListEl = document.getElementById('incident-fixture-list');
    var ctaLink       = document.getElementById('terminal-cta');

    if (!overlay || !closeBtn) return;

    function formatTimestamp(iso) {
        if (!iso) return '—';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString();
    }

    function renderMetaExtra(meta) {
        metaExtraEl.innerHTML = '';
        if (!meta || typeof meta !== 'object') return;

        var frictions = meta.frictions;
        if (frictions && frictions.length) {
            var frictionTitle = document.createElement('div');
            frictionTitle.className = 'incident-meta-title';
            frictionTitle.textContent = 'AFFECTED BONDS';
            metaExtraEl.appendChild(frictionTitle);

            for (var i = 0; i < frictions.length; i++) {
                var f = frictions[i];
                var row = document.createElement('div');
                row.className = 'incident-meta-pair';
                row.textContent = f.a + ' vs ' + f.b + ' (Bond ' + (f.delta > 0 ? '+' : '') + f.delta + ')';
                metaExtraEl.appendChild(row);
            }
            return;
        }

        var skip = { frictions: 1, bonds: 1, counterparty: 1, equityPercent: 1 };
        for (var key in meta) {
            if (!Object.prototype.hasOwnProperty.call(meta, key) || skip[key]) continue;
            var value = meta[key];
            if (value === null || value === undefined || value === '') continue;

            var line = document.createElement('div');
            line.className = 'incident-meta-line';
            line.textContent = key + ': ' + value;
            metaExtraEl.appendChild(line);
        }
    }

    // ── Dossier blocks: the club behind the incident ──
    //
    // Both render from the clicked item's data-dossier
    // (LiveTelemetryService::attachClubDossiers()) and hide entirely when that
    // club has nothing to show, rather than printing an empty heading.

    function renderTopPlayer(player) {
        if (!playerEl) return;

        if (!player || !player.playerName) {
            playerEl.hidden = true;
            return;
        }

        playerNameEl.textContent = player.playerName;

        // Position is recovered from the payload's fixture ratings and age from the
        // stat row — either can legitimately be missing, so the sub-line is built
        // from whichever parts exist rather than printing "null" placeholders.
        var sub = [];
        if (player.position) sub.push(player.position);
        if (player.age) sub.push(player.age + 'y');
        sub.push(player.goals + 'G / ' + player.assists + 'A');
        if (player.appearances) sub.push(player.appearances + ' apps');
        playerSubEl.textContent = sub.join(' · ');

        // Already rounded to 1dp server-side; toFixed keeps a whole number showing
        // as "8.0" rather than "8" so the column doesn't jitter between rows.
        playerRateEl.textContent = Number(player.averageRating || 0).toFixed(1);

        // Only drawn when the client actually reported an appearanceConfig — no
        // generated stand-in. Most clients don't send it yet, so the empty slot is
        // the common case and the row is laid out to not reserve space for it.
        if (playerAvatar) {
            var hasSprite = player.appearanceConfig && typeof composePlayerSpriteSvg === 'function';
            playerAvatar.innerHTML = hasSprite
                ? composePlayerSpriteSvg(player.appearanceConfig, 'player', 'large', 2)
                : '';
        }

        playerEl.hidden = false;
    }

    function renderFixtures(fixtures) {
        if (!fixturesEl) return;

        if (!fixtures || !fixtures.length) {
            fixturesEl.hidden = true;
            return;
        }

        fixtureListEl.innerHTML = '';
        fixtures.forEach(function (fixture) {
            var li = document.createElement('li');
            li.innerHTML =
                '<span class="incident-result-badge is-' + String(fixture.result).toLowerCase() + '"></span>' +
                '<span class="incident-fixture-opponent"></span>' +
                '<span class="incident-fixture-score"></span>';
            // The badge letter doubles as the non-colour cue for the result, so it is
            // text content rather than a colour-only indicator.
            li.querySelector('.incident-result-badge').textContent = fixture.result;
            li.querySelector('.incident-fixture-opponent').textContent = 'vs ' + fixture.opponent;
            li.querySelector('.incident-fixture-score').textContent =
                fixture.scoreFor + '\u2013' + fixture.scoreAgainst + ' \u00b7 Wk ' + fixture.week;
            fixtureListEl.appendChild(li);
        });

        fixturesEl.hidden = false;
    }

    function openIncident(item) {
        var meta = {};
        try {
            meta = JSON.parse(item.getAttribute('data-meta') || '{}');
        } catch (e) {
            meta = {};
        }

        var rawClubBadge = item.getAttribute('data-club-badge');
        var clubBadge = null;
        try { clubBadge = rawClubBadge ? JSON.parse(rawClubBadge) : null; } catch (e) { clubBadge = null; }
        if (crestEl) crestEl.innerHTML = clubBadgeIconsHtml(clubBadge);

        categoryEl.textContent = item.getAttribute('data-category-label') || "OWNER'S DISPATCH";
        clubEl.textContent = item.getAttribute('data-club') || '—';
        timestampEl.textContent = formatTimestamp(item.getAttribute('data-timestamp'));

        var amount = item.getAttribute('data-amount');
        if (amount) {
            amountEl.textContent = amount;
            amountRowEl.style.display = '';
        } else {
            amountRowEl.style.display = 'none';
        }

        detailEl.textContent = item.getAttribute('data-detail') || '';
        renderMetaExtra(meta);

        var dossier = null;
        try { dossier = JSON.parse(item.getAttribute('data-dossier') || 'null'); } catch (e) { dossier = null; }
        renderTopPlayer(dossier && dossier.topPlayer);
        renderFixtures(dossier && dossier.fixtures);

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        stopRotation();
        closeBtn.focus();
    }

    function closeIncident() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        startRotation();
    }

    for (var idx = 0; idx < items.length; idx++) {
        (function (item) {
            item.addEventListener('click', function () { openIncident(item); });
            item.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openIncident(item);
                }
            });
        }(items[idx]));
    }

    if (ctaLink) {
        ctaLink.addEventListener('click', function (e) {
            e.preventDefault();
            openIncident(items[current]);
        });
    }

    closeBtn.addEventListener('click', closeIncident);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeIncident(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeIncident();
    });
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
        transfers: '/api/stats/most-transfers',
        // Was most-development; that field has no writer and always ranked zeroes.
        // See CommunityStatsService::getMostDevelopment().
        wins:      '/api/stats/most-wins',
        seasons:   '/api/stats/most-seasons',
        trophies:  '/api/stats/most-trophies',
    };

    // The stat band sits ABOVE the period tabs, so each figure names its own period.
    var PERIOD_LABELS = {
        week:   'this week',
        month:  'this month',
        season: 'this season',
        all:    'all time',
    };

    var tabs = document.querySelectorAll('#cs-period-tabs .period-tab');
    if (!tabs.length) return;

    var periodLabels = document.querySelectorAll('[data-cs-period]');

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
                '<span class="lb-badge" aria-hidden="true"></span>' +
                '<span class="lb-name"></span>' +
                clubCountryChipHtml(row.country) +
                '<span class="lb-value"></span>';
            // Every club listed on the page carries at least its badge; the kit rides
            // along in the same helper and is hidden by CSS where the row is too narrow.
            li.querySelector('.lb-badge').innerHTML = clubBadgeIconsHtml({
                home: row.homeKitConfig, away: row.awayKitConfig, badge: row.badgeConfig,
            });
            li.querySelector('.lb-name').textContent = row.clubName;
            li.querySelector('.lb-value').textContent = row.value;
            listEl.appendChild(li);
        });
    }

    function sumValues(results) {
        return results.reduce(function (total, row) { return total + row.value; }, 0);
    }

    function loadPeriod(period) {
        for (var i = 0; i < periodLabels.length; i++) {
            periodLabels[i].textContent = PERIOD_LABELS[period] || period;
        }

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
                '<span class="lbg-row-badge" aria-hidden="true"></span>' +
                '<span class="lbg-row-main">' +
                    // .lbg-row-main stacks vertically (name above tag), so the name and
                    // its country chip need their own inline row inside it.
                    '<span class="lbg-row-title">' +
                        '<span class="lbg-row-name"></span>' +
                        clubCountryChipHtml(entry.country) +
                    '</span>' +
                    (entry.displayLabel ? '<span class="lbg-row-tag"></span>' : '') +
                '</span>' +
                '<span class="lbg-row-value"></span>';
            li.querySelector('.lbg-row-badge').innerHTML = clubBadgeIconsHtml({
                home: entry.homeKitConfig, away: entry.awayKitConfig, badge: entry.badgeConfig,
            });
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
