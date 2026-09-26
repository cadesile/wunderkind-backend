// Admin "Kit & Badge" widget — visual swatch/icon-grid picker overlay for
// KitIdentityType's real fields, which templates/admin/field/kit_identity.html.twig
// renders but visually hides (Bootstrap `.visually-hidden`, not `display:none`,
// so native validation/tab-order/screen-reader behavior is untouched).
//
// Three tabs — Kit Home / Kit Away / Badge — and the live preview itself
// switches content with the active tab (see the inline render() script in
// kit_identity.html.twig). Home and Away are each a full independent kit
// (style + two colors + shorts/socks); the form's field names are prefixed
// (`homeKit`, `awayPrimary`, ...) but the sprite's `composeKitSvg` still
// expects the plain unprefixed shape ({kit, primary, secondary, shorts,
// socks}) — `baseKeyFor()` strips the variant prefix to bridge the two.
//
// Every picker reads its options straight from the corresponding hidden
// <select>'s own <option> elements — all of `primary`/`secondary`/`badgeFill`/
// `badgeTrim`/`badgeSymbol` are hex-backed KitColor enums, so swatches use the
// option's own value as the hex directly.
(function () {
  function fieldEl(root, name) {
    return root.querySelector('[id$="_' + name + '"]');
  }

  function setField(root, name, value, opts) {
    var el = fieldEl(root, name);
    if (!el) return;
    el.value = value;
    if (!opts || opts.dispatch !== false) {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function selectOptions(root, name) {
    var el = fieldEl(root, name);
    if (!el || el.tagName !== 'SELECT') return [];
    return Array.from(el.options).map(function (o) {
      return { value: o.value, label: o.textContent };
    });
  }

  /** 'homeShorts' + variant 'home' -> 'shorts' (the plain composeKitSvg config key). */
  function baseKeyFor(name, variant) {
    var rest = name.slice(variant.length);
    return rest.charAt(0).toLowerCase() + rest.slice(1);
  }

  function kitConfigFor(root, variant) {
    function v(base) {
      var el = fieldEl(root, variant + base.charAt(0).toUpperCase() + base.slice(1));
      return el ? el.value : '';
    }
    return { kit: v('kit'), primary: v('primary'), secondary: v('secondary'), shorts: v('shorts'), socks: v('socks') };
  }

  function badgeConfig(root) {
    function v(name) { var el = fieldEl(root, name); return el ? el.value : ''; }
    return {
      badgeShape: v('badgeShape'), badgePattern: v('badgePattern'), badgeCentre: v('badgeCentre'),
      initials: v('initials'), badgeFill: v('badgeFill'), badgeTrim: v('badgeTrim'), badgeSymbol: v('badgeSymbol'),
    };
  }

  function thumbKit(root, variant, overrides, size, scale) {
    if (typeof window.composeKitSvg !== 'function') return '';
    var config = Object.assign(kitConfigFor(root, variant), overrides);
    return window.composeKitSvg(config, 'kit', size, scale);
  }

  function thumbBadge(root, overrides, scale) {
    if (typeof window.composeKitSvg !== 'function') return '';
    var config = Object.assign(badgeConfig(root), overrides);
    return window.composeKitSvg(config, 'badge', null, scale);
  }

  function syncSwatchGroup(root, container, name) {
    var current = fieldEl(root, name) ? fieldEl(root, name).value : '';
    container.querySelectorAll('.ap-swatch').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(btn.getAttribute('data-value') === current));
    });
  }

  function syncGridGroup(root, container, name) {
    var current = fieldEl(root, name) ? fieldEl(root, name).value : '';
    container.querySelectorAll('.ap-grid-btn').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(btn.getAttribute('data-value') === current));
    });
  }

  function syncChipGroup(root, container, name) {
    var current = fieldEl(root, name) ? fieldEl(root, name).value : '';
    container.querySelectorAll('.ap-chip').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(btn.getAttribute('data-value') === current));
    });
  }

  /** primary/secondary/badgeFill/badgeTrim/badgeSymbol are hex-backed KitColor — the option's own value IS the hex. */
  function buildSwatchGroup(root, container, name) {
    container.innerHTML = '';
    var row = document.createElement('div');
    row.className = 'ap-swatch-row';
    selectOptions(root, name).forEach(function (o) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-swatch';
      btn.style.background = o.value;
      btn.setAttribute('aria-label', o.label);
      btn.setAttribute('data-value', o.value);
      btn.addEventListener('click', function () {
        setField(root, name, o.value);
      });
      row.appendChild(btn);
    });
    container.appendChild(row);
    syncSwatchGroup(root, container, name);
  }

  /**
   * @param baseKey the plain composeKitSvg config key this field overrides
   *                ('kit' for homeKit/awayKit, or the field's own name for
   *                badge fields, which aren't prefixed).
   * @param thumbFn (overrides) => svg string — thumbKit(...) or thumbBadge(...),
   *                bound by the caller so this stays variant-agnostic.
   */
  function buildGridGroup(root, container, name, baseKey, thumbFn) {
    container.innerHTML = '';
    var grid = document.createElement('div');
    grid.className = 'ap-grid';
    selectOptions(root, name).forEach(function (o) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-grid-btn';
      btn.setAttribute('data-value', o.value);
      var overrides = {};
      overrides[baseKey] = o.value;
      btn.innerHTML = thumbFn(overrides) + '<span class="ap-grid-label">' + o.label + '</span>';
      btn.addEventListener('click', function () {
        setField(root, name, o.value);
      });
      grid.appendChild(btn);
    });
    container.appendChild(grid);
    syncGridGroup(root, container, name);
  }

  /**
   * shorts/socks are KitPart references ('primary'|'secondary'|'white'|
   * 'black'), not colors themselves — a full kit thumbnail can't show this
   * (shorts/socks aren't visible in the shirt-only crop used for the kit
   * style grid, so every option would render an identical shirt icon
   * regardless of which part color was selected). Render plain color chips
   * instead, resolved against that variant's own live primary/secondary.
   */
  function resolvePartColor(root, variant, partValue) {
    if (partValue === 'primary' || partValue === 'secondary') {
      var el = fieldEl(root, variant + (partValue === 'primary' ? 'Primary' : 'Secondary'));
      return el ? el.value : (partValue === 'primary' ? '#c8202f' : '#f4f3ee');
    }
    if (partValue === 'white') return '#f4f3ee';
    if (partValue === 'black') return '#1a1a1a';
    return '#8a8478';
  }

  function buildColorPartGroup(root, container, name, variant) {
    container.innerHTML = '';
    var row = document.createElement('div');
    row.className = 'ap-swatch-row';
    selectOptions(root, name).forEach(function (o) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-chip';
      btn.setAttribute('data-value', o.value);
      var swatch = document.createElement('span');
      swatch.className = 'ap-chip-swatch';
      swatch.style.background = resolvePartColor(root, variant, o.value);
      var label = document.createElement('span');
      label.className = 'ap-chip-label';
      label.textContent = o.label;
      btn.appendChild(swatch);
      btn.appendChild(label);
      btn.addEventListener('click', function () {
        setField(root, name, o.value);
      });
      row.appendChild(btn);
    });
    container.appendChild(row);
    syncChipGroup(root, container, name);
  }

  function buildInitialsInput(root, container) {
    container.innerHTML = '';
    var input = document.createElement('input');
    input.type = 'text';
    input.maxLength = 3;
    input.className = 'ap-swatch';
    input.style.cssText = 'width:80px;height:32px;border-radius:0;text-align:center;text-transform:uppercase;background:#1e2448;color:#e8f0f4;font-family:"Space Mono",monospace;font-weight:700;letter-spacing:.05em;';
    var field = fieldEl(root, 'initials');
    input.value = field ? field.value : '';
    input.addEventListener('input', function () {
      var cleaned = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3);
      input.value = cleaned;
      setField(root, 'initials', cleaned);
    });
    container.appendChild(input);
  }

  function syncInitialsVisibility(root) {
    var wrap = root.querySelector('[data-kb-initials-wrap]');
    if (!wrap) return;
    var centre = fieldEl(root, 'badgeCentre');
    wrap.hidden = !centre || centre.value !== 'initials';
  }

  window.initKitIdentityWidget = function (root) {
    var sections = Array.from(root.querySelectorAll('[data-kb-field]'));
    var built = {};

    function panelFor(section) {
      return section.closest('[data-kb-panel]');
    }

    function variantFor(section) {
      var panel = panelFor(section);
      return panel ? panel.getAttribute('data-kb-variant') : null;
    }

    function buildSection(section) {
      var name    = section.getAttribute('data-kb-field');
      var mode    = section.getAttribute('data-kb-mode');
      var variant = variantFor(section);

      if (mode === 'swatch') {
        buildSwatchGroup(root, section, name);
      } else if (mode === 'grid') {
        if (variant) {
          var baseKey = baseKeyFor(name, variant);
          buildGridGroup(root, section, name, baseKey, function (overrides) {
            return thumbKit(root, variant, overrides, 'small', 4);
          });
        } else {
          buildGridGroup(root, section, name, name, function (overrides) {
            return thumbBadge(root, overrides, 3);
          });
        }
      } else if (mode === 'colorpart') {
        buildColorPartGroup(root, section, name, variant);
      } else if (mode === 'text') {
        buildInitialsInput(root, section);
      }
      built[name] = true;
    }

    function buildVisiblePanelSections() {
      sections.forEach(function (section) {
        var panel = panelFor(section);
        var name = section.getAttribute('data-kb-field');
        if (panel && !panel.hidden && !built[name]) {
          buildSection(section);
        }
      });
      syncInitialsVisibility(root);
    }

    buildVisiblePanelSections();

    var tabButtons = Array.from(root.querySelectorAll('[data-kb-tab]'));
    tabButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-kb-tab');
        tabButtons.forEach(function (b) {
          b.setAttribute('aria-selected', String(b === btn));
        });
        root.querySelectorAll('[data-kb-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-kb-panel') !== target;
        });
        buildVisiblePanelSections();
        // The preview itself shows a different sprite per tab — the inline
        // render() script listens for `change` on root already.
        root.dispatchEvent(new Event('change'));
      });
    });

    // Every thumbnail renders the whole kit/badge, so any field change
    // invalidates all of them — rebuild the visible tab, mark the other tabs
    // stale (rebuilt lazily next time they're opened).
    root.addEventListener('change', function () {
      Object.keys(built).forEach(function (name) { delete built[name]; });
      buildVisiblePanelSections();
    });

    root.querySelectorAll('[data-kb-swap]').forEach(function (swapBtn) {
      var variant = swapBtn.getAttribute('data-kb-swap');
      swapBtn.addEventListener('click', function () {
        var primary = fieldEl(root, variant + 'Primary'), secondary = fieldEl(root, variant + 'Secondary');
        if (!primary || !secondary) return;
        var p = primary.value, s = secondary.value;
        setField(root, variant + 'Primary', s, { dispatch: false });
        setField(root, variant + 'Secondary', p);
      });
    });

    var matchKitBtn = root.querySelector('[data-kb-match-kit]');
    if (matchKitBtn) {
      matchKitBtn.addEventListener('click', function () {
        var primary = fieldEl(root, 'homePrimary'), secondary = fieldEl(root, 'homeSecondary');
        if (!primary || !secondary) return;
        setField(root, 'badgeFill', primary.value, { dispatch: false });
        setField(root, 'badgeTrim', secondary.value);
      });
    }

    var randomiseBtn = root.querySelector('[data-kb-randomise]');
    if (randomiseBtn) {
      randomiseBtn.addEventListener('click', function () {
        var pickFrom = function (opts) { return opts.length ? opts[Math.floor(Math.random() * opts.length)].value : null; };

        var homePrimary = null, homeSecondary = null;
        ['home', 'away'].forEach(function (variant) {
          ['Kit', 'Shorts', 'Socks'].forEach(function (suffix) {
            var v = pickFrom(selectOptions(root, variant + suffix));
            if (v !== null) setField(root, variant + suffix, v, { dispatch: false });
          });

          var kitColorOpts = selectOptions(root, variant + 'Primary').map(function (o) { return o.value; });
          var primary = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
          var secondary = primary;
          while (secondary === primary && kitColorOpts.length > 1) {
            secondary = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
          }
          setField(root, variant + 'Primary', primary, { dispatch: false });
          setField(root, variant + 'Secondary', secondary, { dispatch: false });
          if (variant === 'home') { homePrimary = primary; homeSecondary = secondary; }
        });

        ['badgeShape', 'badgePattern'].forEach(function (name) {
          var v = pickFrom(selectOptions(root, name));
          if (v !== null) setField(root, name, v, { dispatch: false });
        });

        var kitColorOpts = selectOptions(root, 'badgeFill').map(function (o) { return o.value; });
        // badgeFill: 50% chance equals the home kit's primary, otherwise any kit colour.
        var badgeFill = Math.random() < 0.5 ? homePrimary : kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
        var badgeTrim = badgeFill;
        while (badgeTrim === badgeFill && kitColorOpts.length > 1) {
          badgeTrim = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
        }
        var badgeSymbol = badgeFill;
        while (badgeSymbol === badgeFill && kitColorOpts.length > 1) {
          badgeSymbol = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
        }
        setField(root, 'badgeFill', badgeFill, { dispatch: false });
        setField(root, 'badgeTrim', badgeTrim, { dispatch: false });
        setField(root, 'badgeSymbol', badgeSymbol, { dispatch: false });

        // badgeCentre never rolls 'none' — a blank centre isn't a useful
        // random result, though it's still a valid manual choice.
        var centreOpts = selectOptions(root, 'badgeCentre').filter(function (o) { return o.value !== 'none'; });
        if (centreOpts.length) {
          setField(root, 'badgeCentre', centreOpts[Math.floor(Math.random() * centreOpts.length)].value, { dispatch: false });
        }

        // Initials belong to the club and are never randomised (kept as-is).

        root.dispatchEvent(new Event('change'));
      });
    }
  };
})();
