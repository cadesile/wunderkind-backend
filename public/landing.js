/* ══════════════════════════════════════════════════════════════════════
   Build My Club — landing page behaviour

   Extracted from the former inline <script> blocks in public/index.html.
   No dependencies and no build step, but no longer ES5: the landing redesign
   added IntersectionObserver, Object.assign, Math.imul and NodeList.forEach
   alongside the fetch() that was already here. All baseline in every browser
   that can run the sprite compositors this page loads.
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

// ── Club badge alone (telemetry feed lines) ──
//
// The badge without the kit, for a feed line that may name two clubs. A line
// annotated with badge + kit for each side is four sprites arguing with the
// sentence they belong to, so the ticker takes the badge — the thing that
// identifies a club at 28px — and leaves the kit to the surfaces with room for
// it (the incident modal, the Club Spotlight, the Rich List's club rows).
//
// Merged the same way as clubBadgeIconsHtml(): composeKitSvg() reads only the
// keys relevant to the type it is asked to draw, so one flat object serves both.
function clubBadgeOnlyHtml(clubBadge) {
    if (typeof composeKitSvg !== 'function') return '';

    var cfg = {};
    var home = (clubBadge && clubBadge.home) || {};
    var badge = (clubBadge && clubBadge.badge) || {};
    var key;
    for (key in home) if (Object.prototype.hasOwnProperty.call(home, key)) cfg[key] = home[key];
    for (key in badge) if (Object.prototype.hasOwnProperty.call(badge, key)) cfg[key] = badge[key];

    return composeKitSvg(cfg, 'badge', null, 1.6);
}

// ── Club identity at hero size (incident modal) ──
//
// Same two sprites as clubBadgeIconsHtml(), drawn large instead of at ticker size.
// Separate function rather than a scale argument on that one, because the kit also
// changes crop: 'small' is a shirt-only crop that reads fine at 20px beside a badge,
// but blown up it is a cropped torso — the hero wants the full kit ('large').
//
// Rendered at a fixed scale and then sized down in CSS. The sprites are SVG with a
// viewBox, so they scale cleanly to any box; emitting them big and constraining with
// CSS keeps the responsive sizing in one place rather than splitting it between a JS
// scale constant and a stylesheet.
function clubHeroIconsHtml(clubBadge) {
    if (typeof composeKitSvg !== 'function') return '';

    var cfg = {};
    var home = (clubBadge && clubBadge.home) || {};
    var badge = (clubBadge && clubBadge.badge) || {};
    var key;
    for (key in home) if (Object.prototype.hasOwnProperty.call(home, key)) cfg[key] = home[key];
    for (key in badge) if (Object.prototype.hasOwnProperty.call(badge, key)) cfg[key] = badge[key];

    return composeKitSvg(cfg, 'badge', null, 7) + composeKitSvg(cfg, 'kit', 'large', 7);
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

// ── Shared helpers ───────────────────────────────────────────────────────────

// Every data-* payload on this page is JSON written by Twig's |json_encode, and
// every one of them can legitimately be absent (a club with no kit set, a
// spotlight row predating a column). Parsing was inlined as the same four-line
// try/catch at a dozen call sites before the redesign added a dozen more.
//
// A parse failure returns the fallback rather than throwing: these attributes
// are server-generated, so a malformed one means a bug upstream, and taking the
// whole page's sprite mounting down with it would hide the one section at fault.
function jsonAttr(el, name, fallback) {
    if (!el) return fallback;
    var raw = el.getAttribute(name);
    if (!raw) return fallback;
    try {
        var parsed = JSON.parse(raw);
        return parsed === null ? fallback : parsed;
    } catch (e) {
        return fallback;
    }
}

// An SVG string as an <img>-able data URI.
//
// shape-rendering="crispEdges" is injected rather than set by the compositors
// because it only matters once the sprite is rasterised into an <img>: inline in
// the DOM it inherits the page's own rendering, and image-rendering: pixelated
// in landing.css handles the scaling. Base64 rather than percent-encoded so a
// stray '#' in a hex colour cannot terminate the URI early.
function svgDataUri(svg) {
    var tagged = svg.replace('<svg ', '<svg shape-rendering="crispEdges" ');
    return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(tagged)));
}

// Does the viewer want motion?
//
// Checked at call time, not cached, so a viewer who changes the OS setting with
// the page open gets the new answer on the next rotation decision.
function prefersReducedMotion() {
    return typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

// Mount work deferred until the element is near the viewport.
//
// A full facility site is ~200KB of path data and this page now draws more than
// thirty sprites, so composing them all at parse time would push out first paint
// for sprites nobody has scrolled to. rootMargin starts the work a screen early
// so a scrolling viewer never watches a tile appear.
//
// No IntersectionObserver (or no support) means mount immediately: a slower
// first paint is a far better failure than a page of empty boxes.
function mountWhenVisible(el, mount) {
    if (typeof IntersectionObserver !== 'function') {
        mount();
        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
            if (!entries[i].isIntersecting) continue;
            observer.disconnect();
            mount();
            return;
        }
    }, { rootMargin: '600px 0px' });

    observer.observe(el);
}

// ── Facility sites: the one place a facility tile is drawn ───────────────────
//
// Shared by the Club Spotlight's Facilities panel (one real club's build-out,
// from ClubSpotlight.facilities) and the Facilities section's four sector cards
// (seeded random, decorative). Both draw the same 22 facilities through the same
// compositor, so they cannot disagree about what a facility looks like — only
// about whose facilities they are.
var FacilitySites = (function () {
    // The site order the page renders in, which is also the order the design
    // lists them. FACILITY_SITES is an object and object key order is not
    // something to lean on for layout.
    var ORDER = ['training', 'medical', 'scouting', 'stadium'];

    var COMPOSERS = {
        training: 'composeTrainingGroundSvg',
        medical:  'composeMedicalCentreSvg',
        scouting: 'composeScoutingSvg',
        stadium:  'composeStadiumSvg'
    };

    var DEFAULT_CONFIGS = {
        training: 'DEFAULT_TRAINING_GROUND_CONFIG',
        medical:  'DEFAULT_MEDICAL_CENTRE_CONFIG',
        scouting: 'DEFAULT_SCOUTING_CONFIG',
        stadium:  'DEFAULT_STADIUM_CONFIG'
    };

    // The four stadium stands are the one group whose built state is carried by
    // the LEVEL rather than by a boolean beside it — see stateOf() below.
    var STANDS = { north: 1, east: 1, south: 1, west: 1 };

    // Human labels for the game's own facility slugs.
    //
    // window.FACILITY_SITES.labels holds i18n KEYS (facilities.training.
    // training_pitch), not display text, so they are not usable here. Rather
    // than hand-maintain 22 names, the slug is title-cased and only the ones
    // that read wrong are overridden — so a new facility in the sprite drop gets
    // a reasonable label with no edit here, and a wrong one is one line.
    var LABEL_OVERRIDES = {
        museum: 'Club Museum',
        car_park: 'Car Park',
        club_shop: 'Club Shop',
        scouting_center: 'Scouting Centre',
        advanced_scouting: 'Advanced Scouting Network',
        nutrition_centre: 'Nutrition Centre',
        hydro_pool: 'Hydro Pool',
        biometric_hub: 'Biometric Hub'
    };

    function label(slug) {
        if (LABEL_OVERRIDES[slug]) return LABEL_OVERRIDES[slug];

        return String(slug).split('_').map(function (word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
        }).join(' ');
    }

    function available() {
        if (!window.FACILITY_SITES) return false;
        for (var i = 0; i < ORDER.length; i++) {
            if (typeof window[COMPOSERS[ORDER[i]]] !== 'function') return false;
        }
        return true;
    }

    function compose(site, config, opts, scale) {
        return window[COMPOSERS[site]](config, opts, scale);
    }

    function defaults(site) {
        return window[DEFAULT_CONFIGS[site]] || {};
    }

    // (slug => spriteId) for a site, straight from the bundle. The stadium's own
    // map is STADIUM_SLUGS, already inverted into this shape by the compositor
    // entry, so all four sites read the same way here.
    function slugMap(site) {
        return (window.FACILITY_SITES[site] || {}).slugs || {};
    }

    /**
     * Is this facility built, and at what level, according to `config`?
     *
     * Three shapes have to be read, and the asymmetry is the sprite's, not ours:
     *   - the three plot sites carry `<id>` (bool) and `<id>Level` (1-10);
     *   - the stadium's shop/museum/car park carry `<id>` (bool) and
     *     `<id>Level` (1-10);
     *   - the stadium's four stands carry ONLY `<id>` (1-10), no boolean.
     *
     * For the stands, level 1 is "unbuilt": FacilityConfigBuilder::spriteLevel()
     * is max(1, min(10, gameLevel * 2)), so a game level of 0 yields exactly 1
     * and any real level yields at least 2. That makes `>= 2` a lossless test,
     * and it is the only reason reading built-ness off a level is sound here —
     * the backend's own docblock warns against doing it in general, because the
     * floor would otherwise collide with a real level.
     *
     * The level is reported as the GAME's level (1-5), halving the sprite's
     * 1-10, because that is the number a player recognises from their own
     * facilities screen. `null` means not built, which renders as L–.
     */
    function stateOf(site, config, spriteId) {
        var built, spriteLevel;

        if (site === 'stadium' && STANDS[spriteId]) {
            spriteLevel = config[spriteId];
            built = typeof spriteLevel === 'number' && spriteLevel >= 2;
        } else {
            built = config[spriteId] === true;
            spriteLevel = config[spriteId + 'Level'];
        }

        if (!built || typeof spriteLevel !== 'number') {
            return { built: false, level: null };
        }

        return { built: true, level: Math.max(1, Math.round(spriteLevel / 2)) };
    }

    /**
     * The ground the sprite paints its plot on, so the box behind it can match.
     *
     * Ported from the design drop's own bgOf(): a site's `base` is 'grass' or
     * 'concrete' and the sprite paints the plot accordingly, but the plot is a
     * diamond and the box around it is a rectangle — the corners, and whatever
     * the letterboxing leaves, are the page's background unless it is told.
     *
     * Getting this wrong is not subtle. A concrete site on a green box reads as
     * a brown island floating on grass, which is exactly what shipped before
     * this was wired up. These two hex values are the sprite's ground colours,
     * not page palette, which is why they live here beside the other sprite
     * constants rather than as landing.css tokens.
     *
     * `base` is absent from the real Club Spotlight payload — FacilityConfigBuilder
     * has no opinion about ground type — so it falls through to the sprite's own
     * DEFAULT_*_CONFIG, which is what the renderer will have used.
     */
    function groundColor(site, config) {
        var base = (config && config.base) || defaults(site).base;
        return base === 'concrete' ? '#8a867c' : '#4a7a35';
    }

    /**
     * Re-crops an emitted SVG to what it actually drew.
     *
     * The sprites reserve vertical headroom for the tallest thing that could
     * stand on the plot — a crane, floodlights, a full stand — so a site with
     * nothing tall built comes back with its content pressed into the bottom
     * 70% of the canvas and a band of empty sky above it. Contained in a box,
     * that reads as a picture sliding off the bottom edge.
     *
     * This only rewrites the viewBox and the width/height that follow from it.
     * No path is touched and nothing is re-drawn — the rule against
     * re-implementing the sprites is about drawing, and this is framing.
     *
     * The paths are pixel rectangles using only M/h/v/z (absolute move, relative
     * horizontal, relative vertical, close), so walking them gives an exact
     * bounding box rather than an approximation. Anything unexpected — no
     * paths, a degenerate box, a missing viewBox — returns the SVG untouched,
     * because a wrong crop is far worse than no crop.
     */
    function tightSvg(svg, pad) {
        var vb = /viewBox="0 0 ([\d.]+) ([\d.]+)"/.exec(svg);
        var wh = /width="([\d.]+)" height="([\d.]+)"/.exec(svg);
        if (!vb || !wh) return svg;

        var vw = +vb[1], vh = +vb[2];
        var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
        var found = false;

        var paths = svg.match(/ d="[^"]+"/g) || [];
        for (var i = 0; i < paths.length; i++) {
            var d = paths[i].slice(4, -1);
            var token = /([Mhv])(-?[\d.]+)(?:[ ,](-?[\d.]+))?/g;
            var x = 0, y = 0, m;
            while ((m = token.exec(d)) !== null) {
                if (m[1] === 'M') { x = +m[2]; y = +m[3]; }
                else if (m[1] === 'h') { x += +m[2]; }
                else { y += +m[2]; }
                if (!isFinite(x) || !isFinite(y)) return svg;
                if (x < x0) x0 = x;
                if (x > x1) x1 = x;
                if (y < y0) y0 = y;
                if (y > y1) y1 = y;
                found = true;
            }
        }

        if (!found || x1 <= x0 || y1 <= y0) return svg;

        pad = pad == null ? 1 : pad;
        x0 = Math.max(0, Math.floor(x0 - pad));
        y0 = Math.max(0, Math.floor(y0 - pad));
        x1 = Math.min(vw, Math.ceil(x1 + pad));
        y1 = Math.min(vh, Math.ceil(y1 + pad));

        var w = x1 - x0, h = y1 - y0;
        // Keep the original dp-per-sprite-pixel scale, so a cropped sprite is
        // drawn at the same size as an uncropped one rather than blown up.
        var perPx = (+wh[1]) / vw;

        return svg
            .replace(/viewBox="0 0 [\d.]+ [\d.]+"/, 'viewBox="' + x0 + ' ' + y0 + ' ' + w + ' ' + h + '"')
            .replace(/width="[\d.]+" height="[\d.]+"/,
                'width="' + (w * perPx) + '" height="' + (h * perPx) + '"');
    }

    /** The whole site, drawn as one isometric scene. */
    function heroSvg(site, config, scale) {
        return tightSvg(compose(site, config, { rotation: 0, time: 'day' }, scale || 2));
    }

    /** Is this one of the stadium's four stands? */
    function isStand(site, spriteId) {
        return site === 'stadium' && STANDS[spriteId] === 1;
    }

    /**
     * A stadium config showing one stand and nothing else.
     *
     * The other three stands drop to level 1 — their unbuilt size, since the
     * sprite has no way to remove a stand — and every building, light, board
     * and tree switches off. Without this a "North Stand" tile is a picture of
     * the whole ground with a north stand somewhere in it.
     *
     * The named stand keeps its real level and its real construction flag, so
     * on the Club Spotlight this is still that club's actual stand at its
     * actual size.
     */
    function standOnly(config, side) {
        var cfg = Object.assign({}, config, {
            north: 1, east: 1, south: 1, west: 1,
            northConstruction: false, eastConstruction: false,
            southConstruction: false, westConstruction: false,
            floodlights: false, scoreboard: false,
            shop: false, museum: false, carpark: false, trees: false
        });

        cfg[side] = config[side];
        cfg[side + 'Construction'] = config[side + 'Construction'] === true;

        return cfg;
    }

    /**
     * One facility, cropped out of its own site.
     *
     * `facility` is what makes a single-facility tile possible, and it is passed
     * the full site config rather than a stripped one so the facility sits in
     * the same ground, lighting and accent colour it does on the hero above it.
     *
     * EXCEPT for the four stands. The stadium sprite's `facility` option
     * addresses floodlights, scoreboard, shop, museum, carpark and trees — look
     * at window.FACILITY_SITES.stadium.ids, which is those six and not the
     * stands. The stands live in the same slug map only because
     * STADIUM_SLUGS covers the whole ground, so asking for facility:'north'
     * matches nothing and the sprite returns a bare plot: four identical empty
     * tiles, with no error anywhere. They are isolated by config instead.
     */
    function tileSvg(site, config, spriteId, scale) {
        if (isStand(site, spriteId)) {
            return tightSvg(compose('stadium', standOnly(config, spriteId), { rotation: 0, time: 'day' }, scale || 3));
        }

        return tightSvg(compose(site, config, { facility: spriteId, rotation: 0, time: 'day' }, scale || 3));
    }

    return {
        ORDER: ORDER,
        available: available,
        label: label,
        defaults: defaults,
        slugMap: slugMap,
        stateOf: stateOf,
        isStand: isStand,
        groundColor: groundColor,
        heroSvg: heroSvg,
        tileSvg: tileSvg,
        ids: function (site) { return (window.FACILITY_SITES[site] || {}).ids || []; }
    };
}());

// ── Club Spotlight (real data) ──────────────────────────────────────────────
//
// kit/badge via composeKitSvg(), owner and top-performer avatars via
// composePlayerSpriteSvg(), the stadium and all four facility sites via the
// facility compositor. Every config here is one real club's, read off
// data-* attributes written from the ClubSpotlight row — nothing on this card
// is generated or randomised, which is the whole difference between it and the
// decorative scenes above.
(function () {
    var card = document.querySelector('.spotlight-card');
    if (!card) return;

    var badgeSlot = card.querySelector('.spotlight-badge-slot');
    if (badgeSlot) {
        badgeSlot.innerHTML = clubBadgeIconsHtml(jsonAttr(card, 'data-club-badge', null));
    }

    var ownerSlot = card.querySelector('.spotlight-owner-slot');
    if (ownerSlot && typeof composePlayerSpriteSvg === 'function') {
        // Owner avatars always use the 'staff' body shape — see CLAUDE.md's
        // Owner Identity section (AppearanceRole::OWNER falls into the existing
        // non-player branching server-side).
        ownerSlot.innerHTML = composePlayerSpriteSvg(jsonAttr(card, 'data-owner-appearance', {}), 'staff', 'large', 3);
    }

    // Only present when the top performer has ever reported a sync v2
    // appearanceConfig — most have not (see _spotlight.html.twig).
    var performerSlot = card.querySelector('.spotlight-performer-slot');
    if (performerSlot && typeof composePlayerSpriteSvg === 'function') {
        var performer = jsonAttr(card, 'data-performer-appearance', null);
        if (performer) {
            performerSlot.innerHTML = composePlayerSpriteSvg(performer, 'player', 'large', 3);
        }
    }

    // Home and away strips, full crop. 'full' rather than the ticker's shirt
    // crop: these are the club's kits as an exhibit, so shorts and socks are
    // part of what is being shown.
    if (typeof composeKitSvg === 'function') {
        var kits = { home: jsonAttr(card, 'data-home-kit', null), away: jsonAttr(card, 'data-away-kit', null) };
        card.querySelectorAll('[data-spotlight-kit]').forEach(function (slot) {
            var which = slot.getAttribute('data-spotlight-kit');
            if (!kits[which]) {
                // No away kit configured is common; an empty slot is better than
                // drawing composeKitSvg's defaults and calling them this club's
                // change strip.
                slot.closest('.spotlight-kit').hidden = true;
                return;
            }
            slot.innerHTML = composeKitSvg(kits[which], 'kit', 'full', 3);
        });
    }

    // Honours board — one sprite per trophy chip, each from its own data-trophy.
    // Chips whose tier has no trophy configured carry no attribute at all and
    // stay count-only; a row written before the trophy component was replaced
    // carries the retired trophyImage/trophyColour keys, which parse to no
    // `style` and are skipped rather than drawn wrong (such rows age out within
    // one spotlight cycle — see ClubSpotlight::$trophies).
    if (typeof composeTrophySvg === 'function') {
        card.querySelectorAll('[data-spotlight-trophy]').forEach(function (slot) {
            var parsed = jsonAttr(slot, 'data-spotlight-trophy', null);
            if (parsed && parsed.style) {
                slot.innerHTML = composeTrophySvg(parsed, 1, { background: 'none' });
            }
        });
    }

    // The stadium and the Facilities panel are the two heavy draws on this card
    // (a full site is ~200KB of path data), and the card is tall enough that the
    // panel is below the fold even when the header is not — so both wait for the
    // viewport rather than blocking first paint.
    var stadiumSlot = card.querySelector('.spotlight-stadium-slot');
    if (stadiumSlot && typeof composeStadiumSvg === 'function') {
        mountWhenVisible(stadiumSlot, function () {
            stadiumSlot.innerHTML = composeStadiumSvg(jsonAttr(card, 'data-stadium-config', {}), { rotation: 0, time: 'day' }, 2);
        });
    }

    var panel = card.querySelector('.spotlight-facilities');
    if (panel && FacilitySites.available()) {
        mountWhenVisible(panel, function () { mountFacilitiesPanel(panel); });
    }

    /**
     * The featured club's real build-out: four sector blocks, each a site hero
     * plus one tile per facility with its level badge.
     *
     * The sector list and the facilities within each come from
     * window.FACILITY_SITES, the sprite bundle's own export — the payload
     * supplies state (built, level, accent colour), never structure. An unbuilt
     * facility is still drawn, because the sprite renders an empty plot for it
     * and "this is a plot you have not built on" is the useful thing to show;
     * it carries L– and an is-unbuilt class rather than being omitted.
     */
    function mountFacilitiesPanel(panel) {
        var configs = jsonAttr(panel, 'data-spotlight-facilities', {});
        var builtBySite = jsonAttr(panel, 'data-built-by-site', {});
        var host = panel.querySelector('.spotlight-sectors');
        if (!host) return;

        FacilitySites.ORDER.forEach(function (site) {
            var cfg = configs[site];
            // A site missing from the payload is not an empty site. Every
            // DEFAULT_*_CONFIG in the bundle is the web builder's DEMO config, so
            // merging nothing over it would draw a stranger's fully-built
            // facilities as this club's — skip the block instead.
            if (!cfg) return;

            var block = document.createElement('section');
            block.className = 'spotlight-sector';

            var head = document.createElement('div');
            head.className = 'spotlight-sector-head';

            var title = document.createElement('h4');
            title.className = 'pixel spotlight-sector-name is-' + site;
            title.textContent = site.toUpperCase();

            var count = document.createElement('span');
            count.className = 'spotlight-sector-count vt';
            count.textContent = (builtBySite[site] || 0) + ' BUILT';

            head.appendChild(title);
            head.appendChild(count);

            var hero = document.createElement('img');
            hero.className = 'spotlight-sector-hero';
            hero.alt = 'Isometric view of this club’s ' + site.replace('_', ' ');
            hero.decoding = 'async';
            // No `base` in the spotlight payload — FacilityConfigBuilder has no
            // opinion about ground type — so groundColor() resolves it from the
            // sprite's own default, which is what the renderer itself used.
            hero.style.background = FacilitySites.groundColor(site, cfg);
            hero.src = svgDataUri(FacilitySites.heroSvg(site, cfg, 2));

            var list = document.createElement('ul');
            list.className = 'spotlight-sector-tiles';

            var slugs = FacilitySites.slugMap(site);
            Object.keys(slugs).forEach(function (slug) {
                var spriteId = slugs[slug];
                var state = FacilitySites.stateOf(site, cfg, spriteId);

                var li = document.createElement('li');
                li.className = 'spotlight-tile' + (state.built ? '' : ' is-unbuilt');
                li.title = FacilitySites.label(slug) + (state.built ? ' — level ' + state.level : ' — not built');

                var img = document.createElement('img');
                img.alt = '';
                img.decoding = 'async';
                img.style.background = FacilitySites.groundColor(site, cfg);
                img.src = svgDataUri(FacilitySites.tileSvg(site, cfg, spriteId, 2));

                var name = document.createElement('span');
                name.className = 'spotlight-tile-name vt';
                name.textContent = FacilitySites.label(slug);

                var level = document.createElement('span');
                level.className = 'spotlight-tile-level pixel';
                // L– for a facility with no level to show, so the badge is never
                // simply absent — an empty corner reads as a rendering failure.
                level.textContent = state.built ? 'L' + state.level : 'L–';

                li.appendChild(img);
                li.appendChild(name);
                li.appendChild(level);
                list.appendChild(li);
            });

            block.appendChild(head);
            block.appendChild(hero);
            block.appendChild(list);
            host.appendChild(block);
        });
    }
})();

// ── Owner Spotlight / Rich List (owner avatars, the leader's stadium, club badges) ──
//
// Same renderers as the Club Spotlight, over a list. Two things differ per row
// after the redesign:
//
//   - the CROP. The leader card has room for a full-body owner standing beside
//     their stadium; the four grid cards have a 56px slot, where a full body is
//     an unreadable smudge and the head crop is the whole point. The row says
//     which it wants via data-ospot-crop rather than this file inferring it from
//     a class, so restyling the cards cannot silently change what is drawn.
//   - the STADIUM, which only rank 1 has: OwnerSpotlightService sends
//     stadiumConfig for the leader alone, because the other four cards never
//     draw one and resolving them would be four more ClubFacility queries. A
//     null here means "not the leader", not "no stadium".
//
// Every club row gets at least a badge (clubBadgeIconsHtml falls back to
// composeKitSvg's own defaults for a club that has never customized its
// identity), while the owner avatar is only drawn when that owner has actually
// saved one — owner identity is newer than most accounts, so a null appearance
// is the common case, and an empty slot is better than a default face attributed
// to a named person.
(function () {
    var rows = document.querySelectorAll('.ospot-row');
    if (!rows.length) return;

    Array.prototype.forEach.call(rows, function (row) {
        var crop = row.getAttribute('data-ospot-crop') === 'full' ? 'full' : 'head';

        var avatarSlot = row.querySelector('.ospot-avatar-slot');
        if (avatarSlot && typeof composePlayerSpriteSvg === 'function') {
            var owner = jsonAttr(row, 'data-owner-appearance', null);
            if (owner) {
                // 'staff' body shape — AppearanceRole::OWNER falls into the
                // non-player branching server-side (see CLAUDE.md, Owner Identity).
                avatarSlot.innerHTML = composePlayerSpriteSvg(owner, 'staff', crop, crop === 'full' ? 3 : 2);
            }
        }

        var stadiumSlot = row.querySelector('.ospot-stadium-slot');
        if (stadiumSlot && typeof composeStadiumSvg === 'function') {
            var stadium = jsonAttr(row, 'data-stadium-config', null);
            if (stadium) {
                mountWhenVisible(stadiumSlot, function () {
                    stadiumSlot.innerHTML = composeStadiumSvg(stadium, { rotation: 0, time: 'day' }, 2);
                });
            }
        }

        row.querySelectorAll('.ospot-club').forEach(function (clubEl) {
            var badgeSlot = clubEl.querySelector('.ospot-club-badge');
            if (!badgeSlot) return;
            badgeSlot.innerHTML = clubBadgeIconsHtml(jsonAttr(clubEl, 'data-club-badge', null));
        });
    });
}());

// ── Boardroom Incident & Consequence Feed (terminal + incident inspector modal) ──
(function () {
    var feed = document.querySelector('.terminal-feed');
    var items = document.querySelectorAll('.terminal-feed-item');
    if (!items.length) return;

    var current = 0;
    var rotateTimer = null;
    var pauseBtn = document.getElementById('telemetry-pause');

    // Paused state has three sources and one flag: the viewer pressing the
    // button, the incident modal being open, and prefers-reduced-motion. The
    // last one is a starting state rather than a lock — a viewer who asked the
    // OS for less motion can still press play on one specific thing, and taking
    // that choice away would be the more patronising reading of the setting.
    var paused = prefersReducedMotion();

    // With the rotation off there is no reason to show one line at a time, and
    // every reason not to: the other nineteen are real content. CSS reveals the
    // whole list for .is-static, so a reduced-motion viewer reads the feed as a
    // list instead of watching a single frozen line.
    if (paused && feed) feed.classList.add('is-static');

    function next() {
        items[current].classList.remove('active');
        current = (current + 1) % items.length;
        items[current].classList.add('active');
    }

    function startRotation() {
        if (paused || rotateTimer !== null || items.length < 2) return;
        rotateTimer = setInterval(next, 4500);
    }

    function stopRotation() {
        if (rotateTimer === null) return;
        clearInterval(rotateTimer);
        rotateTimer = null;
    }

    function syncPauseButton() {
        if (!pauseBtn) return;
        pauseBtn.setAttribute('aria-pressed', paused ? 'true' : 'false');
        // The label states what pressing it will DO, which is the convention the
        // rest of this page's buttons follow; aria-pressed carries the state.
        pauseBtn.textContent = paused ? 'RESUME FEED' : 'PAUSE FEED';
    }

    if (pauseBtn) {
        pauseBtn.addEventListener('click', function () {
            paused = !paused;
            if (feed) feed.classList.toggle('is-static', paused);
            if (paused) {
                stopRotation();
            } else {
                startRotation();
            }
            syncPauseButton();
        });
    }

    syncPauseButton();
    startRotation();

    // Render each line's club badges once up front — cheap (<= 20 lines, a badge
    // or two each) and it avoids re-composing the same SVG every time the
    // rotation reveals a row.
    //
    // A badge per club the line NAMES, not just the first: a transfer names both
    // sides and the design draws both. The slots are emitted by Twig only for
    // clubs that have a real Club row behind them — a name the client sent as
    // free text (an NPC side, typically) carries no config, and drawing
    // composeKitSvg's defaults for it would invent a club identity.
    //
    // Badge only, no kit, unlike clubBadgeIconsHtml(): two clubs on one line at
    // badge + kit each is four sprites competing with the text they annotate.
    Array.prototype.forEach.call(items, function (item) {
        item.querySelectorAll('.terminal-feed-badge[data-club-badge]').forEach(function (slot) {
            slot.innerHTML = clubBadgeOnlyHtml(jsonAttr(slot, 'data-club-badge', null));
        });
    });

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
        if (crestEl) crestEl.innerHTML = clubHeroIconsHtml(clubBadge);

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

    // Each line is a real <button> now, so Enter and Space already activate it
    // and it is already focusable and already announced as a control. The
    // keydown shim that used to live here existed only because these were divs
    // carrying tabindex="0" role="button".
    Array.prototype.forEach.call(items, function (item) {
        item.addEventListener('click', function () { openIncident(item); });
    });

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
//
// Store URLs are operator-set (GameConfig, edited at /admin/app-links) and
// served by GET /api/app-links — never hardcoded here, which is why the buttons
// ship hidden and are revealed by this request rather than the other way round.
//
// Driven by data-store-link rather than element ids. The redesign took the page
// from two store CTAs to six (hero, testers, closing CTA), and the previous
// version named five specific ids and called document.getElementById on each
// one unguarded — adding a seventh button meant editing this file, and removing
// one threw a TypeError that killed the whole block, taking the social links
// down with it.
//
// Five roles, all hidden until this resolves:
//   android / ios        the real store links
//   android-missing      shown only when there is no Android link
//   ios-fallback         shown only when there is no iOS link (opens the beta form)
//   pending              shown only when NEITHER platform has a link
(function () {
    function show(role, href) {
        document.querySelectorAll('[data-store-link="' + role + '"]').forEach(function (el) {
            if (href && 'href' in el) el.href = href;
            el.style.display = '';
        });
    }

    function setNote(text) {
        var note = document.getElementById('download-note');
        if (note) note.textContent = text;
    }

    function wire(data) {
        var android = data && data.android;
        var ios = data && data.ios;

        if (android) show('android', android);
        else show('android-missing');

        if (ios) show('ios', ios);
        else show('ios-fallback');

        if (!android && !ios) show('pending');

        // Social links are hidden until a URL exists for them, same as the store
        // buttons; the ids are unique per link so a loop buys nothing here.
        [['facebook', 'nav-facebook-link'], ['facebook', 'footer-facebook-link'],
         ['x', 'nav-x-link'], ['x', 'footer-x-link']].forEach(function (pair) {
            var url = data && data[pair[0]];
            var el = document.getElementById(pair[1]);
            if (!url || !el) return;
            el.href = url;
            el.style.display = '';
        });

        var missing = [];
        if (!android) missing.push('Android');
        if (!ios) missing.push('iOS');

        setNote(missing.length
            ? missing.join(' & ') + ' version' + (missing.length > 1 ? 's' : '')
                + ' not yet available. Free to play. Works fully offline. No ads in gameplay.'
            : 'Free to play. Works fully offline. No ads in gameplay.');
    }

    fetch('/api/app-links')
        .then(function (r) { return r.json(); })
        .then(wire)
        .catch(function () {
            // Reveal every fallback: a failed request is not evidence a platform
            // is unavailable, so the page offers the routes that work without a
            // store URL rather than claiming the game cannot be had.
            show('pending');
            show('android-missing');
            show('ios-fallback');
            setNote('Download links temporarily unavailable. Please check back soon.');
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

// ── Decorative sprite scenes ────────────────────────────────────────────────
//
// Ported from design/random.js.src in the landing redesign export, which is the
// SPEC for "random within role" — which hair styles a staff member can have,
// how often a facility is switched on, how likely a stand is to be under
// construction. Those numbers are design decisions, so they are carried across
// as they were written rather than re-chosen here.
//
// The one dependency that did not survive the port is window.BMC_ISO, the
// design-time sprite bundle: it is built from an older facility engine (its
// trees and lighting differ from production), so every draw call here goes
// through the real compositors in public/assets instead. That is also why
// design/sprites.js is not shipped.
//
// These scenes are DECORATIVE and must stay visibly distinct from the page's
// real-data panels, which is why nothing generated here is ever labelled with a
// club or owner name: the Club Spotlight and the Rich List draw real clubs and
// real owners, and a random stadium presented the same way would be a claim
// about somebody's club.
//
// Seeded ONCE per page load, so every scene is stable while the page is open —
// a scene that re-rolled on each IntersectionObserver callback would change
// under a viewer scrolling back up — and different on the next reload.
var Scenes = (function () {
    var seed = (Math.random() * 4294967296) >>> 0;

    function hash(s) {
        var h = 2166136261;
        for (var i = 0; i < s.length; i++) {
            h ^= s.charCodeAt(i);
            h = Math.imul(h, 16777619);
        }
        return h >>> 0;
    }

    // One deterministic stream per key, all derived from the page seed — so
    // mount order cannot change what a given scene looks like. A single shared
    // stream would make every scene depend on which ones were drawn first,
    // i.e. on scroll order.
    function rng(key) {
        var a = (seed ^ hash(key)) >>> 0;
        var f = function () {
            a |= 0;
            a = a + 0x6D2B79F5 | 0;
            var t = Math.imul(a ^ a >>> 15, 1 | a);
            t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
            return ((t ^ t >>> 14) >>> 0) / 4294967296;
        };
        f.pick = function (xs) { return xs[Math.floor(f() * xs.length)]; };
        f.int = function (lo, hi) { return lo + Math.floor(f() * (hi - lo + 1)); };
        f.chance = function (p) { return f() < p; };
        return f;
    }

    function ids(xs) { return xs.map(function (x) { return x.id; }); }

    var HAIR = ['crop', 'quiff', 'long', 'bun', 'mohawk', 'afro', 'buzz', 'cornrows', 'bald'];
    var HAIR_COLORS = ['#2a1a10', '#1a1a1a', '#c8602a', '#8a8a8a', '#d9b25a', '#5a3418', '#f4f3ee'];
    var SKIN = ['s1', 's2', 's3', 's4', 's5', 's6'];
    var FACES = ['neutral', 'happy', 'wink', 'cool', 'focused'];
    // Weighted by repetition, not by a weight table: three of five draws are
    // clean-shaven. The design wrote it this way and the ratio is the point.
    var FACIAL = ['none', 'none', 'none', 'stubble', 'beard'];
    var SIZES = ['small', 'medium', 'large'];
    // No Q/V/X/Y/Z: club initials are drawn from a pixel alphabet and these read
    // poorly or are absent at badge size.
    var LETTERS = 'ABCDEFGHIJKLMNOPRSTUW';

    function kitOptions() { return window.KIT_SPRITE_OPTIONS; }

    /** A coherent club identity: kit, badge and initials that go together. */
    function club(r) {
        var KO = kitOptions();
        var colors = KO.KIT_COLORS;
        var primary = r.pick(colors);
        var secondary;
        // Redrawn until it differs: a kit whose primary and secondary match
        // renders as a flat shape with no pattern visible at all.
        do { secondary = r.pick(colors); } while (secondary === primary);

        return {
            kit: r.pick(ids(KO.KIT_STYLES)),
            primary: primary,
            secondary: secondary,
            shorts: r.pick(ids(KO.KIT_PARTS)),
            socks: r.pick(ids(KO.KIT_PARTS)),
            badgeShape: r.pick(ids(KO.BADGE_SHAPES)),
            badgePattern: r.pick(ids(KO.BADGE_PATTERNS)),
            badgeCentre: r.pick(ids(KO.BADGE_CENTRES)),
            initials: LETTERS[r.int(0, LETTERS.length - 1)] + LETTERS[r.int(0, LETTERS.length - 1)],
            badgeFill: primary,
            badgeTrim: secondary,
            badgeSymbol: r.pick(colors)
        };
    }

    /** The same club's change strip: colours swapped, pattern switched. */
    function away(c) {
        return Object.assign({}, c, {
            kit: c.kit === 'plain' ? 'hoops' : 'plain',
            primary: c.secondary,
            secondary: c.primary
        });
    }

    /**
     * A person. `c` puts them in a club's kit (a player); `staff` true gives
     * them the staff body shape, an outfit and a chance of glasses.
     */
    function person(r, c, staff) {
        var cfg = {
            hair: r.pick(HAIR),
            hairColor: r.pick(HAIR_COLORS),
            skin: r.pick(SKIN),
            face: r.pick(FACES),
            facial: r.pick(FACIAL),
            height: r.pick(SIZES),
            weight: r.pick(SIZES),
            glasses: !!(staff && r.chance(0.3)),
            outfit: r.pick(['coat', 'suit', 'track', 'jumper']),
            trousers: 'black'
        };

        if (c) {
            cfg.primary = c.primary;
            cfg.secondary = c.secondary;
            cfg.kit = c.kit;
            cfg.shorts = c.shorts;
            cfg.socks = c.socks;
        }

        return cfg;
    }

    function body(cfg, type, scale) { return window.composePlayerSpriteSvg(cfg, type, 'full', scale || 6); }
    function badge(c, scale) { return window.composeKitSvg(c, 'badge', 'full', scale || 6); }
    function kitFull(c, scale) { return window.composeKitSvg(c, 'kit', 'full', scale || 7); }

    /** A whole stadium. Stands run 1-10, buildings 1-5, per the sprite's ranges. */
    function stadium(r, over) {
        var colors = kitOptions().KIT_COLORS;
        var seat = r.pick(colors);
        var roof;
        do { roof = r.pick(colors); } while (roof === seat);

        return Object.assign({
            north: r.int(1, 10), east: r.int(1, 10), south: r.int(1, 10), west: r.int(1, 10),
            northConstruction: r.chance(0.15), eastConstruction: r.chance(0.15),
            southConstruction: r.chance(0.15), westConstruction: r.chance(0.15),
            seat: seat, roof: roof,
            floodlights: r.chance(0.6), scoreboard: r.chance(0.6),
            shop: r.chance(0.6), museum: r.chance(0.6), carpark: r.chance(0.6), trees: r.chance(0.6),
            shopLevel: r.int(1, 5), museumLevel: r.int(1, 5), carparkLevel: r.int(1, 5),
            home: r.int(0, 4), away: r.int(0, 4),
            pitch: r.pick(['stripes', 'checks', 'plain']),
            base: r.pick(['concrete', 'grass'])
        }, over || {});
    }

    /**
     * A plot site (training / medical / scouting).
     *
     * Levels 1-5, facilities on 85%, construction 12% — the design's own
     * numbers. Note the sprite itself accepts levels up to 10; sampling the
     * lower half is deliberate, so a decorative site does not look like a
     * maxed-out club.
     *
     * Unlike the real-data path, construction IS rolled here: the backend has no
     * notion of an upgrade in progress and so never claims one, but a decorative
     * site is allowed to show scaffolding because that is a real game state the
     * section is describing.
     */
    function site(kind, r) {
        var cfg = Object.assign({}, FacilitySites.defaults(kind));

        FacilitySites.ids(kind).forEach(function (id) {
            cfg[id] = r.chance(0.85);
            cfg[id + 'Level'] = r.int(1, 5);
            cfg[id + 'Construction'] = r.chance(0.12);
        });

        cfg.trees = r.chance(0.75);
        cfg.accent = r.pick(kitOptions().KIT_COLORS);
        cfg.base = r.pick(['concrete', 'grass']);
        if ('pitch' in cfg) cfg.pitch = r.pick(['stripes', 'checks', 'plain']);

        return cfg;
    }

    /** A site config for any of the four, so callers need no per-site branch. */
    function siteConfig(kind, key) {
        var r = rng(key);
        return kind === 'stadium'
            ? stadium(r, { museum: true, shop: true, carpark: true })
            : site(kind, r);
    }

    /**
     * A stadium whose only notable feature is one stand, at a random size.
     *
     * Isolating it — flattening the other three, switching the buildings off —
     * is FacilitySites.standOnly()'s job, because the Club Spotlight needs the
     * same isolation applied to a real club's config. This only decides how big
     * the stand is and whether it is mid-build, which are the design's numbers.
     */
    function standTile(side, key) {
        var r = rng(key);
        var cfg = stadium(r);
        cfg[side] = r.int(2, 10);
        cfg[side + 'Construction'] = r.chance(0.25);

        return cfg;
    }

    function trophy(r) {
        var O = window.TROPHY_SPRITE_OPTIONS;
        var primary = r.pick(O.KIT_COLORS);
        var secondary;
        do { secondary = r.pick(O.KIT_COLORS); } while (secondary === primary);

        return window.composeTrophySvg({
            style: r.pick(ids(O.TROPHY_STYLES)),
            finish: r.pick(ids(O.TROPHY_FINISHES)),
            ribbons: r.chance(0.6),
            ribbonPrimary: primary,
            ribbonSecondary: secondary
        }, 6);
    }

    /**
     * Lays sprites out on a striped pitch, as one SVG.
     *
     * Each item is nested as its own <svg> with its original viewBox, scaled to
     * the requested height and centred on `x`, standing on the baseline unless
     * it names its own `y`. That keeps every sprite's internal coordinates
     * untouched — the alternative, rewriting path data to position things, is
     * exactly the "never re-implement drawing" rule this file is built around.
     */
    function compose(items, w, h) {
        w = w || 520;
        h = h || 400;

        var stripes = '';
        for (var i = 0; i < Math.ceil(w / 80); i++) {
            stripes += '<rect x="' + (i * 80) + '" width="80" height="' + h + '" fill="'
                + (i % 2 ? '#608f37' : '#6b9a3e') + '"/>';
        }

        var out = '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h
            + '" viewBox="0 0 ' + w + ' ' + h + '">' + stripes
            + '<rect y="' + (h - 18) + '" width="' + w + '" height="18" fill="#4a7a35"/>';

        items.forEach(function (it) {
            // Four numbers, not two: FacilitySites.heroSvg() re-crops a site to
            // what it drew, so a facility sprite arrives with a non-zero origin
            // (viewBox="0 53 248 133"). A regex anchored on "0 0" matched
            // nothing and this dropped the stadium out of the scene silently.
            var m = /viewBox="([-\d.]+) ([-\d.]+) ([\d.]+) ([\d.]+)"/.exec(it.svg);
            if (!m) return;

            var vx = +m[1];
            var vy = +m[2];
            var vw = +m[3];
            var vh = +m[4];
            var ph = it.h;
            var pw = vw / vh * ph;
            var y = (it.y == null ? h - 12 : it.y) - ph;
            var inner = it.svg.replace(/^<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '');

            out += '<svg x="' + (it.x - pw / 2) + '" y="' + y + '" width="' + pw
                + '" height="' + ph + '" viewBox="' + vx + ' ' + vy + ' ' + vw + ' ' + vh + '">'
                + inner + '</svg>';
        });

        return svgDataUri(out + '</svg>');
    }

    /**
     * The named compositions the templates ask for by data-scene.
     *
     * Built lazily and memoised: a scene costs several sprite renders, and the
     * dressing-room pair and the journey chapters are far enough apart on the
     * page that most visits never scroll to all of them.
     */
    var cache = {};

    var BUILDERS = {
        owner: function () {
            var c1 = club(rng('sc1'));
            var c2 = club(rng('sc2'));
            return compose([
                { svg: staff('sco'), h: 250, x: 170 },
                { svg: badge(c1, 8), h: 96, x: 340, y: 200 },
                { svg: kitFull(c1), h: 110, x: 420, y: 200 },
                { svg: kitFull(c2), h: 110, x: 340, y: 330 },
                { svg: badge(c2, 8), h: 90, x: 430, y: 330 }
            ]);
        },
        leadership: function () {
            return compose([0, 1, 2, 3].map(function (i) {
                return { svg: staff('scl' + i), h: 230, x: 90 + i * 120 };
            }));
        },
        advance: function () {
            var c1 = club(rng('sc1'));
            return compose([
                { svg: FacilitySites.heroSvg('stadium', siteConfig('stadium', 'sca'), 3), h: 250, x: 260, y: 300 },
                { svg: player('scap1', c1), h: 140, x: 90 },
                { svg: player('scap2', c1), h: 140, x: 430 }
            ]);
        },
        league: function () {
            var c1 = club(rng('sc1'));
            var c2 = club(rng('sc2'));
            return compose([0, 1, 2, 3].map(function (i) {
                return { svg: trophy(rng('scy' + i)), h: 150, x: 100 + i * 110 };
            }).concat([
                { svg: badge(c1, 8), h: 80, x: 150, y: 260 },
                { svg: badge(c2, 8), h: 80, x: 370, y: 260 }
            ]));
        },
        match: function () {
            var c1 = club(rng('sc1'));
            var c2 = club(rng('sc2'));
            return compose([
                { svg: kitFull(c1), h: 120, x: 130, y: 150 },
                { svg: kitFull(c2), h: 120, x: 390, y: 150 },
                { svg: player('scm1', c1), h: 190, x: 130 },
                { svg: player('scm2', c1), h: 190, x: 260 },
                { svg: player('scm3', c1), h: 190, x: 390 }
            ]);
        },
        dr1: function () {
            var c1 = club(rng('sc1'));
            return compose([0, 1, 2, 3].map(function (i) {
                return { svg: player('scd' + i, c1), h: 200, x: 150 + i * 90 };
            }));
        },
        dr2: function () {
            var c1 = club(rng('sc1'));
            return compose([
                { svg: player('scd5', c1), h: 230, x: 170 },
                { svg: player('scd6', c1), h: 230, x: 350 }
            ]);
        },
        scout1: function () {
            var c2 = club(rng('sc2'));
            return compose([
                { svg: staff('scs0'), h: 240, x: 150 },
                { svg: player('scs1', c2), h: 160, x: 300 },
                { svg: player('scs2', c2), h: 160, x: 400 }
            ]);
        },
        scout2: function () {
            return compose([0, 1, 2].map(function (i) {
                return { svg: staff('sca' + i), h: 240, x: 130 + i * 130 };
            }));
        }
    };

    function staff(key) { return body(person(rng(key), null, true), 'staff', 6); }
    function player(key, c) { return body(person(rng(key), c, false), 'player', 6); }

    function ready() {
        return typeof window.composePlayerSpriteSvg === 'function'
            && typeof window.composeKitSvg === 'function'
            && typeof window.composeTrophySvg === 'function'
            && !!window.KIT_SPRITE_OPTIONS
            && !!window.TROPHY_SPRITE_OPTIONS
            && FacilitySites.available();
    }

    /** A scene as a data URI, or null when a compositor is missing or the name is unknown. */
    function get(name) {
        if (!BUILDERS[name] || !ready()) return null;
        if (!cache[name]) cache[name] = BUILDERS[name]();
        return cache[name];
    }

    return { get: get, rng: rng, siteConfig: siteConfig, standTile: standTile, ready: ready };
}());

// ── Mount the decorative scenes ─────────────────────────────────────────────
//
// Each .scene box already reserves its aspect ratio in CSS and ships an <img>
// with width/height attributes and authored alt text, so a scene that never
// mounts leaves correctly-sized empty space and an accurate description rather
// than a layout shift.
(function () {
    var boxes = document.querySelectorAll('.scene[data-scene]');
    if (!boxes.length) return;

    Array.prototype.forEach.call(boxes, function (box) {
        var img = box.querySelector('img');
        if (!img) return;

        mountWhenVisible(box, function () {
            var uri = Scenes.get(box.getAttribute('data-scene'));
            if (!uri) return;
            img.src = uri;
            box.classList.add('is-mounted');
        });
    });
}());

// ── Facilities section: four decorative sector cards + the upgrade path ─────
(function () {
    var cards = document.querySelectorAll('[data-facility-site]');
    var path = document.querySelector('[data-upgrade-path]');
    if (!cards.length && !path) return;
    if (!FacilitySites.available()) return;

    function tileMarkup(site, cfg, slug, spriteId, levelLabel) {
        var li = document.createElement('li');
        li.className = 'sector-tile';

        var img = document.createElement('img');
        // Decorative: the facility's name is right there in the caption, so
        // repeating it as alt text would have a screen reader say it twice.
        img.alt = '';
        img.decoding = 'async';
        // The box behind the sprite is painted its own ground, not a fixed
        // green: the plot is a diamond inside a rectangle, so a concrete site
        // on a grass box reads as a brown island floating on a lawn.
        img.style.background = FacilitySites.groundColor(site, cfg);
        img.src = svgDataUri(FacilitySites.tileSvg(site, cfg, spriteId, 3));

        var name = document.createElement('span');
        name.className = 'sector-tile-name vt';
        name.textContent = FacilitySites.label(slug);

        li.appendChild(img);
        li.appendChild(name);

        if (levelLabel) {
            var badge = document.createElement('span');
            badge.className = 'sector-tile-level pixel';
            badge.textContent = levelLabel;
            li.appendChild(badge);
        }

        return li;
    }

    Array.prototype.forEach.call(cards, function (card) {
        var site = card.getAttribute('data-facility-site');
        var heroBox = card.querySelector('[data-facility-hero]');
        var tileList = card.querySelector('[data-facility-tiles]');

        mountWhenVisible(card, function () {
            var cfg = Scenes.siteConfig(site, 'site-' + site);

            var heroImg = heroBox && heroBox.querySelector('img');
            if (heroImg) {
                heroBox.style.background = FacilitySites.groundColor(site, cfg);
                heroImg.src = svgDataUri(FacilitySites.heroSvg(site, cfg, 3));
                heroBox.classList.add('is-mounted');
            }

            if (!tileList) return;

            var slugs = FacilitySites.slugMap(site);
            Object.keys(slugs).forEach(function (slug) {
                var spriteId = slugs[slug];
                // A stand gets its own stadium so it can have its own size; the
                // isolation (flattening the other three, switching the buildings
                // off) is FacilitySites.tileSvg's job, not this loop's.
                var tileCfg = FacilitySites.isStand(site, spriteId)
                    ? Scenes.standTile(spriteId, 'stand-' + spriteId)
                    : cfg;

                tileList.appendChild(tileMarkup(site, tileCfg, slug, spriteId, null));
            });
        });
    });

    // Upgrade path: one facility, five tiles, one per GAME level.
    //
    // The design file renders these as physioLevel 1..5, but the sprite's own
    // level scale is 1-10 and the game's is 1-5 — FacilityConfigBuilder doubles
    // one into the other. Passing the game level straight through would label
    // sprite level 3 as "LEVEL 3" while the game calls that level 2 (one and a
    // half, really), and the five tiles would only span the bottom half of the
    // range the copy promises. So each label n draws sprite level n * 2.
    if (path) {
        mountWhenVisible(path, function () {
            var spriteId = path.getAttribute('data-upgrade-path');
            var base = Scenes.siteConfig('medical', 'physio-path');

            [1, 2, 3, 4, 5].forEach(function (n) {
                var cfg = Object.assign({}, base);
                cfg[spriteId] = true;
                cfg[spriteId + 'Level'] = n * 2;
                cfg[spriteId + 'Construction'] = false;

                var li = document.createElement('li');
                li.className = 'upgrade-level';

                var img = document.createElement('img');
                img.alt = 'Physio clinic at level ' + n;
                img.decoding = 'async';
                img.style.background = FacilitySites.groundColor('medical', cfg);
                img.src = svgDataUri(FacilitySites.tileSvg('medical', cfg, spriteId, 4));

                var label = document.createElement('div');
                label.className = 'upgrade-level-label pixel' + (n === 5 ? ' is-gold' : '');
                label.textContent = 'LEVEL ' + n;

                li.appendChild(img);
                li.appendChild(label);
                path.appendChild(li);
            });
        });
    }
}());
