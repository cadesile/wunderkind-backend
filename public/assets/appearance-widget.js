// Admin "Appearance" widget — visual swatch/icon-grid/toggle picker overlay
// for AppearanceType's real fields, which templates/admin/field/appearance.html.twig
// renders but visually hides (Bootstrap `.visually-hidden`, not `display:none`,
// so native validation/tab-order/screen-reader behavior is untouched).
//
// This script never hardcodes an enum's value list: every picker reads its
// options straight from the corresponding hidden <select>'s own <option>
// elements (colour swatches read the hex straight off the option's own value,
// since hairColor/lip/primary/secondary are all hex-backed enums), so it can
// never drift from what AppearanceType actually allows. Picking an option
// sets the hidden field's `.value` (or `.checked` for the two boolean
// fields) and dispatches a native `change` event, which the inline render()
// in appearance.html.twig already listens for to rebuild the big live
// preview via window.composePlayerSpriteSvg — this widget doesn't touch that
// preview directly.
(function () {
  function fieldEl(root, name) {
    return root.querySelector('[id$="_' + name + '"]');
  }

  function currentAppearance(root) {
    function v(name) {
      var el = fieldEl(root, name);
      if (!el) return '';
      return el.type === 'checkbox' ? el.checked : el.value;
    }
    return {
      hair: v('hair'), hairColor: v('hairColor'), headband: !!v('headband'), skin: v('skin'),
      face: v('face'), facial: v('facial'), lip: v('lip'),
      primary: v('primary'), secondary: v('secondary'),
      kit: v('kit'), shorts: v('shorts'), socks: v('socks'),
      outfit: v('outfit'), trousers: v('trousers'), glasses: !!v('glasses'),
    };
  }

  function setField(root, name, value, opts) {
    var el = fieldEl(root, name);
    if (!el) return;
    if (el.type === 'checkbox') {
      el.checked = !!value;
    } else {
      el.value = value;
    }
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

  function thumb(root, personType, overrides, size) {
    if (typeof window.composePlayerSpriteSvg !== 'function') return '';
    var appearance = Object.assign(currentAppearance(root), overrides);
    return window.composePlayerSpriteSvg(appearance, personType, 'small', size);
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

  function syncToggle(root, container, name) {
    var el = fieldEl(root, name);
    var on = el ? el.checked : false;
    var track = container.querySelector('.ap-toggle-track');
    var thumbEl = container.querySelector('.ap-toggle-thumb');
    if (track) track.style.background = on ? '#E8CF59' : '#4a5568';
    if (thumbEl) thumbEl.style.transform = on ? 'translateX(14px)' : 'translateX(0)';
  }

  function buildSwatchGroup(root, container, name, swatches) {
    container.innerHTML = '';
    var row = document.createElement('div');
    row.className = 'ap-swatch-row';
    swatches.forEach(function (s) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-swatch';
      btn.style.background = s.hex;
      btn.setAttribute('aria-label', s.label);
      btn.setAttribute('data-value', s.value);
      btn.addEventListener('click', function () {
        setField(root, name, s.value);
      });
      row.appendChild(btn);
    });
    container.appendChild(row);
    syncSwatchGroup(root, container, name);
  }

  /** hairColor/lip/primary/secondary are hex-backed enums — the option's own value IS the hex. */
  function buildPlainSwatchGroup(root, container, name) {
    var opts = selectOptions(root, name).map(function (o) {
      return { value: o.value, label: o.label, hex: o.value };
    });
    buildSwatchGroup(root, container, name, opts);
  }

  /** skin is an id, not a hex — look its base colour up via window.SKIN_SHADES. */
  function buildSkinSwatchGroup(root, container, name) {
    var opts = selectOptions(root, name).map(function (o) {
      var shade = (window.SKIN_SHADES && window.SKIN_SHADES[o.value]) || { c: '#8a8478' };
      return { value: o.value, label: o.label, hex: shade.c };
    });
    buildSwatchGroup(root, container, name, opts);
  }

  function buildGridGroup(root, container, name, options, personType, thumbSize) {
    container.innerHTML = '';
    var grid = document.createElement('div');
    grid.className = 'ap-grid';
    options.forEach(function (o) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-grid-btn';
      btn.setAttribute('data-value', o.value);
      var overrides = {};
      overrides[name] = o.value;
      btn.innerHTML = thumb(root, personType, overrides, thumbSize)
        + '<span class="ap-grid-label">' + o.label + '</span>';
      btn.addEventListener('click', function () {
        setField(root, name, o.value);
      });
      grid.appendChild(btn);
    });
    container.appendChild(grid);
    syncGridGroup(root, container, name);
  }

  /**
   * shorts/socks/trousers are KitPart references ('primary'|'secondary'|
   * 'white'|'black'), not colors themselves — they resolve to whatever the
   * live primary/secondary fields currently hold (or the fixed white/black),
   * exactly like the compositor's own col() helper. A full kit/sprite
   * thumbnail can't show this: shorts/socks aren't visible in the head-and-
   * shoulders 'small' crop used for every other grid field, so every option
   * rendered as an identical shirt icon regardless of which part color was
   * selected. Render plain color chips instead.
   */
  function resolvePartColor(root, partValue) {
    if (partValue === 'primary') { var p = fieldEl(root, 'primary'); return p ? p.value : '#c8202f'; }
    if (partValue === 'secondary') { var s = fieldEl(root, 'secondary'); return s ? s.value : '#f4f3ee'; }
    if (partValue === 'white') return '#f4f3ee';
    if (partValue === 'black') return '#1a1a1a';
    return '#8a8478';
  }

  function syncChipGroup(root, container, name) {
    var current = fieldEl(root, name) ? fieldEl(root, name).value : '';
    container.querySelectorAll('.ap-chip').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(btn.getAttribute('data-value') === current));
    });
  }

  function buildColorPartGroup(root, container, name) {
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
      swatch.style.background = resolvePartColor(root, o.value);
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

  function buildToggle(root, container, name, label) {
    container.innerHTML = '';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ap-toggle-btn';
    btn.innerHTML =
      '<span class="ap-toggle-track"><span class="ap-toggle-thumb"></span></span>'
      + '<span class="ap-toggle-label">' + label + '</span>';
    btn.addEventListener('click', function () {
      var el = fieldEl(root, name);
      setField(root, name, !(el && el.checked));
    });
    container.appendChild(btn);
    syncToggle(root, container, name);
  }

  window.initAppearanceWidget = function (root, personType) {
    var sections = Array.from(root.querySelectorAll('[data-ap-field]'));
    var built = {}; // field name -> true once its section's markup exists

    function panelFor(section) {
      return section.closest('[data-appearance-panel]');
    }

    function buildSection(section) {
      var name = section.getAttribute('data-ap-field');
      var mode = section.getAttribute('data-ap-mode');
      if (mode === 'swatch') {
        buildPlainSwatchGroup(root, section, name);
      } else if (mode === 'skinswatch') {
        buildSkinSwatchGroup(root, section, name);
      } else if (mode === 'grid') {
        buildGridGroup(root, section, name, selectOptions(root, name), personType, 56);
      } else if (mode === 'colorpart') {
        buildColorPartGroup(root, section, name);
      } else if (mode === 'toggle') {
        buildToggle(root, section, name, section.getAttribute('data-ap-label') || name);
      }
      built[name] = true;
    }

    function buildVisiblePanelSections() {
      sections.forEach(function (section) {
        var panel = panelFor(section);
        var name = section.getAttribute('data-ap-field');
        if (panel && !panel.hidden && !built[name]) {
          buildSection(section);
        }
      });
    }

    // Build the initially-visible tab eagerly; the other tab builds lazily
    // the first time it's opened (see the tab click handler below).
    buildVisiblePanelSections();

    var tabButtons = Array.from(root.querySelectorAll('[data-appearance-tab]'));
    tabButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-appearance-tab');
        tabButtons.forEach(function (b) {
          b.setAttribute('aria-selected', String(b === btn));
        });
        root.querySelectorAll('[data-appearance-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-appearance-panel') !== target;
        });
        buildVisiblePanelSections();
      });
    });

    // Every thumbnail renders the *whole* sprite, so any single field
    // changing invalidates all of them, not just its own group. Rebuild the
    // currently-visible tab immediately; the hidden tab is marked stale and
    // rebuilds lazily next time it's opened, via buildVisiblePanelSections.
    root.addEventListener('change', function () {
      Object.keys(built).forEach(function (name) { delete built[name]; });
      buildVisiblePanelSections();
    });

    var randomiseBtn = root.querySelector('[data-appearance-randomise]');
    if (randomiseBtn) {
      randomiseBtn.addEventListener('click', function () {
        // Players always default to a neutral expression; only staff/scout/agent get a varied one.
        var randomFields = personType === 'player'
          ? ['hair', 'hairColor', 'skin', 'facial', 'lip']
          : ['hair', 'hairColor', 'skin', 'facial', 'lip', 'face'];
        randomFields.forEach(function (name) {
          var opts = selectOptions(root, name);
          if (!opts.length) return;
          var pick = opts[Math.floor(Math.random() * opts.length)];
          setField(root, name, pick.value, { dispatch: false });
        });
        if (personType === 'player') {
          setField(root, 'face', 'neutral', { dispatch: false });
        }
        setField(root, 'headband', Math.random() < (personType === 'player' ? 0.001 : 0.20), { dispatch: false });

        var kitColorOpts = selectOptions(root, 'primary');
        if (kitColorOpts.length) {
          var primaryPick = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
          var secondaryPick = primaryPick;
          while (secondaryPick.value === primaryPick.value && kitColorOpts.length > 1) {
            secondaryPick = kitColorOpts[Math.floor(Math.random() * kitColorOpts.length)];
          }
          setField(root, 'primary', primaryPick.value, { dispatch: false });
          setField(root, 'secondary', secondaryPick.value, { dispatch: false });
        }

        if (personType === 'player') {
          ['kit', 'shorts', 'socks'].forEach(function (name) {
            var opts = selectOptions(root, name);
            if (!opts.length) return;
            var pick = opts[Math.floor(Math.random() * opts.length)];
            setField(root, name, pick.value, { dispatch: false });
          });
        } else {
          ['outfit', 'trousers'].forEach(function (name) {
            var opts = selectOptions(root, name);
            if (!opts.length) return;
            var pick = opts[Math.floor(Math.random() * opts.length)];
            setField(root, name, pick.value, { dispatch: false });
          });
          setField(root, 'glasses', Math.random() < 0.30, { dispatch: false });
        }

        // One synthetic change on the root (not a real field) triggers both
        // the existing preview render() and our rebuild listener above
        // exactly once, instead of once per randomised field.
        root.dispatchEvent(new Event('change'));
      });
    }
  };
})();
