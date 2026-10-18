// Admin "Trophy" widget — visual grid/swatch/toggle picker overlay for
// TrophyConfigType's real fields, which templates/admin/field/trophy_config.html.twig
// renders but visually hides (Bootstrap `.visually-hidden`, not `display:none`,
// so native validation/tab-order/screen-reader behavior is untouched).
//
// Third sibling of appearance-widget.js and kit-identity-widget.js, and
// deliberately the same shape: a thumbnail grid per enum field, hex swatches for
// the colour fields, and a Randomise button, all driving the hidden inputs and
// letting the inline render() script in the Twig template redraw the preview.
//
// One structural difference: an empty `style` is the stored "no trophy" state
// (TrophyConfigType::NO_TROPHY), so this widget has a No-trophy toggle that
// blanks `style` and hides the rest of the controls, rather than offering an
// empty tile inside the style grid — an empty tile among trophy thumbnails
// reads as a broken asset rather than a deliberate choice.
//
// Every picker reads its options straight from the corresponding hidden
// <select>'s own <option> elements, so the vocabulary comes from the PHP enums
// (which themselves mirror the sprite's TROPHY_STYLES / TROPHY_FINISHES /
// KIT_COLORS) and never from a second hand-maintained copy here. The ribbon
// fields are hex-backed KitColor, so an option's own value IS the hex.
(function () {
  /** Thumbnails are small and there are 10 of them, so keep the grid cheap. */
  var THUMB_SCALE = 2;

  function fieldEl(root, name) {
    return root.querySelector('[id$="_' + name + '"]');
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
    return Array.from(el.options)
      // The placeholder <option value=""> is the "no trophy" choice, surfaced
      // by its own toggle instead — see this file's header.
      .filter(function (o) { return o.value !== ''; })
      .map(function (o) { return { value: o.value, label: o.textContent }; });
  }

  function currentConfig(root) {
    function v(name) {
      var el = fieldEl(root, name);
      if (!el) return '';
      return el.type === 'checkbox' ? el.checked : el.value;
    }
    return {
      // A thumbnail must still draw while "no trophy" is selected, so an empty
      // style falls back to the sprite's own default rather than to nothing.
      style: v('style') || (window.DEFAULT_TROPHY_CONFIG || {}).style,
      finish: v('finish'),
      ribbons: !!v('ribbons'),
      ribbonPrimary: v('ribbonPrimary'),
      ribbonSecondary: v('ribbonSecondary'),
    };
  }

  /** One trophy thumbnail with `overrides` applied over the live config. */
  function thumb(root, overrides) {
    if (typeof window.composeTrophySvg !== 'function') return '';
    return window.composeTrophySvg(
      Object.assign(currentConfig(root), overrides),
      THUMB_SCALE,
      // Transparent, no shadow: these sit on the picker's own panel, and a teal
      // tile behind each of 10 thumbnails would swamp the selected-state ring.
      { background: 'none' },
    );
  }

  function syncPressed(root, container, name, selector) {
    var el = fieldEl(root, name);
    var current = el ? el.value : '';
    container.querySelectorAll(selector).forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(btn.getAttribute('data-value') === current));
    });
  }

  function buildGridGroup(root, container, name) {
    container.innerHTML = '';
    var grid = document.createElement('div');
    grid.className = 'ap-grid';
    selectOptions(root, name).forEach(function (o) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ap-grid-btn';
      btn.setAttribute('data-value', o.value);
      var overrides = {};
      overrides[name] = o.value;
      btn.innerHTML = thumb(root, overrides) + '<span class="ap-grid-label">' + o.label + '</span>';
      btn.addEventListener('click', function () {
        setField(root, name, o.value);
      });
      grid.appendChild(btn);
    });
    container.appendChild(grid);
    syncPressed(root, container, name, '.ap-grid-btn');
  }

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
    syncPressed(root, container, name, '.ap-swatch');
  }

  function syncToggle(container, on) {
    var track = container.querySelector('.ap-toggle-track');
    var thumbEl = container.querySelector('.ap-toggle-thumb');
    if (track) track.style.background = on ? '#E8CF59' : '#4a5568';
    if (thumbEl) thumbEl.style.transform = on ? 'translateX(14px)' : 'translateX(0)';
  }

  function buildToggle(container, label, isOn, onClick) {
    container.innerHTML = '';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ap-toggle-btn';
    btn.innerHTML =
      '<span class="ap-toggle-track"><span class="ap-toggle-thumb"></span></span>'
      + '<span class="ap-toggle-label">' + label + '</span>';
    btn.addEventListener('click', onClick);
    container.appendChild(btn);
    syncToggle(container, isOn());
  }

  window.initTrophyConfigWidget = function (root) {
    var sections = Array.from(root.querySelectorAll('[data-tr-field]'));
    var noneWrap = root.querySelector('[data-tr-none-toggle]');
    var configWrap = root.querySelector('[data-tr-config]');
    var ribbonColours = root.querySelector('[data-tr-ribbon-colours]');

    function hasTrophy() {
      var el = fieldEl(root, 'style');
      return !!(el && el.value);
    }

    /**
     * The No-trophy toggle reads inverted on purpose: it is labelled for the
     * state an admin is turning ON ("Awards a trophy"), so the switch being off
     * and the controls being hidden line up.
     */
    function buildNoneToggle() {
      if (!noneWrap) return;
      buildToggle(noneWrap, 'Awards a trophy', hasTrophy, function () {
        if (hasTrophy()) {
          setField(root, 'style', '');
        } else {
          var first = selectOptions(root, 'style')[0];
          var fallback = (window.DEFAULT_TROPHY_CONFIG || {}).style;
          setField(root, 'style', fallback || (first ? first.value : ''));
        }
      });
    }

    function buildSections() {
      sections.forEach(function (section) {
        var name = section.getAttribute('data-tr-field');
        var mode = section.getAttribute('data-tr-mode');
        if (mode === 'grid') {
          buildGridGroup(root, section, name);
        } else if (mode === 'swatch') {
          buildSwatchGroup(root, section, name);
        } else if (mode === 'toggle') {
          buildToggle(
            section,
            section.getAttribute('data-tr-label') || name,
            function () { var el = fieldEl(root, name); return !!(el && el.checked); },
            function () {
              var el = fieldEl(root, name);
              setField(root, name, !(el && el.checked));
            },
          );
        }
      });
    }

    function syncVisibility() {
      if (configWrap) configWrap.hidden = !hasTrophy();
      // Ribbon colours are meaningless with ribbons off, and leaving two dead
      // swatch rows on screen invites an admin to "fix" a colour that isn't drawn.
      var ribbonsEl = fieldEl(root, 'ribbons');
      if (ribbonColours) ribbonColours.hidden = !(ribbonsEl && ribbonsEl.checked);
    }

    buildNoneToggle();
    buildSections();
    syncVisibility();

    // Every thumbnail renders the whole trophy, so any field change invalidates
    // all of them — rebuild the lot. Ten 34x46 sprites is cheap enough that
    // staleness-tracking (as the kit widget needs across its tabs) would be
    // more machinery than it saves.
    root.addEventListener('change', function () {
      buildNoneToggle();
      buildSections();
      syncVisibility();
    });

    var swapBtn = root.querySelector('[data-tr-swap]');
    if (swapBtn) {
      swapBtn.addEventListener('click', function () {
        var primary = fieldEl(root, 'ribbonPrimary');
        var secondary = fieldEl(root, 'ribbonSecondary');
        if (!primary || !secondary) return;
        var p = primary.value, s = secondary.value;
        setField(root, 'ribbonPrimary', s, { dispatch: false });
        setField(root, 'ribbonSecondary', p);
      });
    }

    var randomiseBtn = root.querySelector('[data-tr-randomise]');
    if (randomiseBtn) {
      randomiseBtn.addEventListener('click', function () {
        // The compositor bundle exposes the web builder's own randomiser
        // (uniform style and finish, ribbons on 60% of the time, distinct
        // ribbon colours), so the admin and the app roll trophies alike —
        // rather than this file keeping a second set of rules.
        var config = typeof window.randomTrophyConfig === 'function'
          ? window.randomTrophyConfig()
          : null;
        if (!config) return;

        setField(root, 'style', config.style, { dispatch: false });
        setField(root, 'finish', config.finish, { dispatch: false });
        setField(root, 'ribbons', config.ribbons, { dispatch: false });
        setField(root, 'ribbonPrimary', config.ribbonPrimary, { dispatch: false });
        setField(root, 'ribbonSecondary', config.ribbonSecondary, { dispatch: false });

        root.dispatchEvent(new Event('change'));
      });
    }
  };
})();
