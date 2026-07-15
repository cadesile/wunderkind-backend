// Admin avatar compositor — adapted, de-TypeScripted copy of the frontend's
// avatarSvgLayers.ts + avatarCompositor.ts (wunderkind-app), combined into a
// single plain-JS browser script for the EasyAdmin live preview widget.
//
// Source: wunderkind-app/src/assets/avatarSvgLayers.ts
//         wunderkind-app/src/utils/avatarCompositor.ts
//
// The compositor is pure string manipulation with no React-Native imports,
// so it runs unchanged in the browser once TypeScript-only syntax (type
// annotations, `as const`, interfaces, imports) is stripped.

// ─── Inner SVG content for face avatar layers (no <svg> wrapper). ─────────────
// All layers share a 200×200 viewBox and stack transparently.
//
// Skin-tone placeholder colors (replaced at runtime by avatarCompositor):
//   #ffdbbf = face lit area   #e3b680 = face shadow/cheek
//   #e3b992 = bald scalp mid  #ffd1c8 = bald scalp highlight
//   #b98b** = nose/chin deep shadow (all #b98b hex variants)
//
// Hair placeholder colors are defined per-style in HAIR_TEMPLATES below.
// Jersey placeholder colors are in JERSEY_KIT_PLACEHOLDERS below.

// ─── Face (shape variants keyed by faceShape) ─────────────────────────────────

const FACE_LAYERS = {
  // face/face.svg — oval (default)
  oval:
    `<g stroke-width="0"><path d="M73 26.5V29h-5v5h-5v5h-5v15h-5v19h-5v5h-5v24h5v5h5v5h5v14h5v5h5v44h5v5h5v5h9v4h25v-4h9v-5h5v-5h5v-44h5v-5h5v-14h5v-5h5v-5h4V78h-4v-5h-5V54h-5V39h-5v-5h-5v-5h-5v-5H73zm53 5V34h5v5h5v19h5v39h5V78h5v24h-5v5h-5V97h-5v29h-5v5h-5v5h-5v5h5v34h-5v5h-9v5H87v-5h-9v-5h-5v-34h5v-5h-5v-5h-5v-5h-5V97h-5v10h-5v-5h-5V78h5v19h5V58h5V39h5v-5h5v-5h53z"/><path d="M78 143.5v2.5h4v5h35v-5h4v-5h-4v5H82v-5h-4z"/></g><g fill="#e3b680" stroke-width="0"><path d="M73 31.5V34h-5v5h-5v19h-5v39h-5v10h5V97h5V63h5V39h5v-5h53v5h5v24h5v34h5v10h5V97h-5V58h-5V39h-5v-5h-5v-5H73z"/><path d="M63 114v12h5v5h5v5h5v5h-5v34h5v-24h4v5h5v4h5v5h5v5h5v-5h5v-5h5v-4h5v-5h4v24h5v-34h-5v-5h5v-5h5v-5h5v-24h-5v10h-5v5h-5v9h-4v5h-5v15h5v5H82v-5h5v-15h-5v-5h-4v-9h-5v-5h-5v-10h-5zm19 29.5v2.5h-4v-5h4zm39 0v2.5h-4v-5h4z"/></g><path fill="#ffdbbf" d="M73 36.5V39h-5v24h-5v39h5v10h5v5h5v9h4v5h5v15h25v-15h5v-5h4v-9h5v-5h5v-10h5V63h-5V39h-5v-5H73zM48 90v12h5V78h-5zm98 0v12h5V78h-5zm-68 75.5V180h9v5h25v-5h9v-29h-4v5h-5v4h-5v5h-5v5h-5v-5h-5v-5h-5v-4h-5v-5h-4z"/>`,

  // face/face 2.svg — round (wider cheeks, blush)
  round:
    `<g stroke-width="0"><path d="M73 26.5V29h-5v5h-5v5h-5v15h-5v19h-5v5h-5v24h5v5h5v5h5v14h5v5h5v44h5v5h5v5h9v4h25v-4h9v-5h5v-5h5v-44h5v-5h5v-14h5v-5h5v-5h4V78h-4v-5h-5V54h-5V39h-5v-5h-5v-5h-5v-5H73zm53 5V34h5v5h5v19h5v39h5V78h5v24h-5v5h-5V97h-5v29h-5v5h-5v5h-5v5h5v34h-5v5h-9v5H87v-5h-9v-5h-5v-34h5v-5h-5v-5h-5v-5h-5V97h-5v10h-5v-5h-5V78h5v19h5V58h5V39h5v-5h5v-5h53z"/><path d="M78 143.5v2.5h4v5h35v-5h4v-5h-4v5H82v-5h-4z"/></g><g fill="#e3b680" stroke-width="0"><path d="M73 31.5V34h-5v5h-5v19h-5v39h-5v10h5V97h5V63h5V39h5v-5h53v5h5v24h5v34h5v10h5V97h-5V58h-5V39h-5v-5h-5v-5H73z"/><path d="M63 104.5v2.5h5v-5h-5zm68 0v2.5h5v-5h-5zM73 158v17h5v-24h4v5h5v4h5v5h5v5h5v-5h5v-5h5v-4h5v-5h4v24h5v-34h-5v5h-4v5H82v-5h-4v-5h-5z"/></g><path fill="#ffdbbf" d="M73 36.5V39h-5v24h-5v39h5v10h5v5h5v9h4v5h5v15h25v-15h5v-5h4v-9h5v-5h5v-10h5V63h-5V39h-5v-5H73zM48 90v12h5V78h-5zm98 0v12h5V78h-5zm-68 75.5V180h9v5h25v-5h9v-29H78z"/><path fill="#c6b6bf" d="M63 116.5v9.5h5v5h5v5h5v5h4v5h5v-15h-5v-5h-4v-9h-5v-5h-5v-5h-5zm68-7v2.5h-5v5h-5v9h-4v5h-5v15h5v-5h4v-5h5v-5h5v-5h5v-19h-5z"/>`,

  // face/face 3.svg — square (different chin structure)
  square:
    `<g stroke-width="0"><path d="M73 26.5V29h-5v5h-5v5h-5v15h-5v19h-5v5h-5v24h5v5h5v5h5v14h5v5h5v44h5v5h5v5h9v4h25v-4h9v-5h5v-5h5v-44h5v-5h5v-14h5v-5h5v-5h4V78h-4v-5h-5V54h-5V39h-5v-5h-5v-5h-5v-5H73zm53 5V34h5v5h5v19h5v39h5V78h5v24h-5v5h-5V97h-5v29h-5v5h-5v5h-5v5h5v34h-5v5h-9v5H87v-5h-9v-5h-5v-34h5v-5h-5v-5h-5v-5h-5V97h-5v10h-5v-5h-5V78h5v19h5V58h5V39h5v-5h5v-5h53z"/><path d="M78 143.5v2.5h4v5h35v-5h4v-5h-4v5H82v-5h-4z"/></g><g fill="#e3b680" stroke-width="0"><path d="M73 31.5V34h-5v5h-5v19h-5v39h-5v10h5V97h5V63h5V39h5v-5h53v5h5v24h5v34h5v10h5V97h-5V58h-5V39h-5v-5h-5v-5H73z"/><path d="M63 114v12h5v5h5v5h5v5h-5v34h5v-19h9v4h5v5h15v-5h5v-4h9v19h5v-34h-5v-5h5v-5h5v-5h5v-24h-5v10h-5v5h-5v9h-4v5h-5v15h5v5H82v-5h5v-15h-5v-5h-4v-9h-5v-5h-5v-10h-5zm19 29.5v2.5h-4v-5h4zm39 0v2.5h-4v-5h4z"/></g><path fill="#ffdbbf" d="M73 36.5V39h-5v24h-5v39h5v10h5v5h5v9h4v5h5v15h25v-15h5v-5h4v-9h5v-5h5v-10h5V63h-5V39h-5v-5H73zM48 90v12h5V78h-5zm98 0v12h5V78h-5zm-68 78v12h9v5h25v-5h9v-24h-9v4h-5v5H92v-5h-5v-4h-9z"/>`,
};

// ─── Eyes (morale-driven) ─────────────────────────────────────────────────────

const EYE_LAYERS = {
  // eye/happy.svg — wide, bright
  happy:
    `<path d="M68 65.5V68h-5v5h5v-5h5v9h15v-9h5v-5H68zm39 0V68h5v9h15v-9h5v5h4v-5h-4v-5h-25z"/><path fill="#fff" d="M68 72.5V77h5v-9h-5zm20 0V77h5v-9h-5zm19 0V77h5v-9h-5zm20 0V77h5v-9h-5z"/>`,
  // eye/normal.svg — open, content
  content:
    `<path d="M63 65.5V68h-5v5h5v-5h10v9h10v-9h9v-5H63zm44 0V68h9v9h10v-9h10v5h4v-5h-4v-5h-29z"/><path fill="#fff" d="M68 72.5V77h5v-9h-5zm15 0V77h4v-9h-4zm29 0V77h4v-9h-4zm14 0V77h5v-9h-5z"/>`,
  // eye/lean.svg — slightly narrow, neutral
  neutral:
    `<path d="M63 65.5V68h10v4h10v-4h10v-5H63zm44 0V68h10v4h10v-4h9v-5h-29z"/><path fill="#fff" d="M68 70v2h5v-4h-5zm15 0v2h5v-4h-5zm29 0v2h5v-4h-5zm15 0v2h5v-4h-5z"/>`,
  // eye/lean1.svg — angled, lower-set (round eyeShape variant)
  lean1:
    `<path d="M58 60.5V63h5v5h10v4h10v-4h9v-5H63v-5h-5zm78 0V63h-29v5h9v4h10v-4h10v-5h4v-5h-4z"/><path fill="#fff" d="M68 70v2h5v-4h-5zm15 0v2h4v-4h-4zm29 0v2h4v-4h-4zm14 0v2h5v-4h-5z"/>`,
  // eye/pale.svg — tired/sunken
  low:
    `<path d="M68 65.5V68h-5v5h5v-5h25v-5H68zm39 0V68h25v5h4v-5h-4v-5h-25zm-34 10V78h10v-5H73zm44 0V78h10v-5h-10z"/><path fill="#b98b4c" d="M68 70.5V73h-5v5h5v4h20v-4h5v-5h-5v-5H68zm20 5V78H68v-5h20zm24-5V73h-5v5h5v4h20v-4h4v-5h-4v-5h-20zm20 5V78h-20v-5h20z"/><path fill="#fff" d="M68 75.5V78h5v-5h-5zm15 0V78h5v-5h-5zm29 0V78h5v-5h-5zm15 0V78h5v-5h-5z"/>`,
  // eye/low.svg — heavy-lidded, dejected
  dejected:
    `<path d="M63 65.5V68h30v-5H63zm44 0V68h29v-5h-29zm-34 10V78h10v-5H73zm44 0V78h10v-5h-10z"/><path fill="#b98b4c" d="M68 70.5V73h20v-5H68zm44 0V73h20v-5h-20zM68 80v2h20v-4H68zm44 0v2h20v-4h-20z"/><path fill="#fff" d="M68 75.5V78h5v-5h-5zm15 0V78h5v-5h-5zm29 0V78h5v-5h-5zm15 0V78h5v-5h-5z"/>`,
};

// ─── Nose ─────────────────────────────────────────────────────────────────────

const NOSE_LAYERS = {
  // nose/normal.svg
  normal:
    `<path fill="#b98b66" d="M92 85.5V93h-5v9h14v-4h-9v-5h5V78h-5z"/>`,
  // nose/small.svg
  small:
    `<path fill="#b98b66" d="M92 94v7h9v-4h-4V87h-5z"/>`,
};

// ─── Mouth (morale-driven) ────────────────────────────────────────────────────

const MOUTH_LAYERS = {
  // mouth/smile.svg — biggest smile
  happy:
    `<path fill="#b98b00" d="M82 114.5v2.5h5v4h24v-4H87v-5h-5z"/>`,
  // mouth/happy.svg — slight upward curve
  content:
    `<path fill="#b98b55" d="M82 114.5v2.5h5v4h25v-4h4v-5h-4v5H87v-5h-5z"/>`,
  // mouth/normal.svg — flat
  normal:
    `<path fill="#b98b46" d="M87 119v2h24v-4H87z"/>`,
  // mouth/smile1.svg — asymmetric/mild
  low:
    `<path fill="#b98b55" d="M112 114.5v2.5H87v4h25v-4h4v-5h-4z"/>`,
  // mouth/low.svg — downturned corners
  dejected:
    `<path fill="#b98b46" d="M87 119.5v2.5h-5v4h5v-4h25v4h4v-4h-4v-5H87z"/>`,
};

// ─── Hair ─────────────────────────────────────────────────────────────────────
// Hair-color placeholder pairs are defined in HAIR_TEMPLATES below.
// Bald uses skin-tone colors and is rendered behind the face.

const HAIR_LAYERS = {
  // hair/bald.svg — scalp only; uses skin placeholder colors
  bald:
    `<path d="M73 26.5V29h-5v5h-5v5h-5v19h5V39h5v-5h5v-5h53v5h5v5h5v19h4V39h-4v-5h-5v-5h-5v-5H73z"/><path fill="#e3b992" d="M73 31.5V34h-5v5h-5v19h5V39h5v-5h53v5h5v19h5V39h-5v-5h-5v-5H73z"/><path fill="#ffd1c8" d="M73 36.5V39h-5v19h63V39h-5v-5H73z"/>`,
  // hair/classic.svg — side-parted; template: #552e1a / #8e4633
  classic:
    `<path d="M78 6.5V9H63v5H53v5h-5v5h-5v53h15V63h-5v10h-5V24h5v-5h10v-5h15V9h34v5h14v5h10v5h5v5h5v5h5v24h-5v10h-5V58h-5v-5h-5v-5H87v-5h-5v-4h-9v4h-5v5h-5v5h-5v5h5v-5h5v-5h5v-5h9v5h5v5h44v5h5v10h5v9h10V58h4V34h-4v-5h-5v-5h-5v-5h-5v-5h-10V9h-14V4H78z"/><path fill="#552e1a" d="M78 11.5V14h19v5h10v5h19v5h5v5h10v9h-5v-4h-19v-5h-15v-5H92v-5H73V14H63v5H53v5h-5v49h5V63h5V53h5v-5h5v-5h5v-4h9v4h5v5h44v5h5v5h5v10h5V58h5V34h-5v-5h-5v-5h-5v-5h-10v-5h-14V9H78zm68 34V48h-5v-5h5z"/><path fill="#8e4633" d="M73 19v5h19v5h10v5h15v5h19v4h5v5h5v-5h-5v-9h-10v-5h-5v-5h-19v-5H97v-5H73z"/>`,
  // hair/messy.svg — dishevelled; template: #712400 / #8e4920
  messy:
    `<path d="M73 2.5V5h-4v5H59V5h-5v15h-5v10H39v5h5v9h-5v20h5v10h5v4h5v-4h5V64h5V54h5v-5h19v5h24v-5h20v5h4v10h5v14h10v-4h5V64h5V54h4V44h-4v-9h-5V25h-5v-5h-5v-5h-5v-5h-5V0h-4v5h-5v5h-5V0h-15v10h-5V5h-4V0H88v5h-5V0H73zm10 10V20h5v-5h5V5h5v5h4v5h5v-5h5V5h5v5h5v5h5v-5h9v5h5v15h5v-5h5v10h5v9h5v10h-5v10h-5v10h-5V64h-5V54h-5v-5h-4v-5h-20v5H88v-5H69v5h-5v5h-5v10h-5v10h-5V64h-5V44h5v-9h5V25h5V15h5v5h5v-5h4v-5h5V5h5z"/><path fill="#712400" d="M78 12.5V20h-5v10h-4v14h-5v-5h-5v-9h-5v9h5v5h5v5h-5v5h-5v5h-5v15h5V64h5V54h5v-5h5v-5h9v-5h-5v-9h5V20h5V5h-5zm20 5V20h-5v10h-5v9h-5v5h5v5h24v-5h15v-9h5V25h4V15h-4v10h-5v10h-5v4h-10v-4h5V25h5V15h-5v10h-5v10h-5v4h-5v5h-9V30h5V20h4v-5h-4zm14 24V44h-5v-5h5zm34-9V35h-5v4h-5v5h-4v5h4v5h5v10h5v10h5V59h-5v-5h-5V39h5v-4h5v-5h-5z"/><path fill="#8e4920" d="M93 10v5h-5v5H78V10h-5v5h-4v5h-5v-5h-5v10h-5v5h5v9h-5v-4h-5v9h-5v20h5v-5h5v-5h5v-5h5v-5h5V30h4v9h5v5h5v-5h5v-9h5v14h9v-5h5v5h5v-5h10v-4h5v9h9v-5h5v15h5v5h5v5h5V54h5V44h-5v-9h-10v-5h5v-5h-5v5h-5V15h-5v-5h-9v5h-5v-5h-5V5h-5v5h-5v5h-5v-5h-4V5h-5zm9 7.5V20h-4v10h-5V20h5v-5h4zm20 2.5v5h-5v10h-5v4h-5v-4h5V25h5V15h5zm14 0v5h-4v10h-5V25h5V15h4zm-58 5v5h-5V20h5zm68 12v2h-5v-4h5zm-82 4.5V44h-5v-5h5z"/>`,
  // hair/roud.svg — rounded dome; wrapped in hair color at runtime
  round:
    `<path d="M64 2.5V5H54v5h-5v5h-5v10h-5v14h5v20h5v19h10V59h5V49h5v-5h62v5h5v10h5v19h10V54h5V39h4V25h-4V15h-5v-5h-5V5h-10V0H64z"/>`,
  // hair/smart.svg — neat side-parted; template: #552e24 / #8e4637
  smart:
    `<path d="M78 6.5V9H63v5H53v5h-5v5h-5v49h5v5h10V63h5V53h5v-5h5v-5h9v5h5v5h10v-5H87v-5h-5v-4h-9v-5H63v5h10v4h-5v5h-5v5h-5v10h-5v5h-5V24h5v-5h10v-5h15V9h34v5h14v5h10v5h5v5h5v5h5v24h-5v20h-5v-5h-5v-5h-5v-5h-5v-5h-5v-5h-19v5h19v5h5v5h5v5h5v5h5v4h5v-4h5V58h4V34h-4v-5h-5v-5h-5v-5h-5v-5h-10V9h-14V4H78z"/><path fill="#552e24" d="M78 11.5V14h19v5h24v5h10v5h5v5h5v9h-5v-4h-10v-5h-9v-5H97v-5H82v-5h-9v-5H63v5H53v5h-5v44h5v-5h5V53h5v-5h5v-5h5v-4h9v4h5v5h10v5h24v5h5v5h5v5h5v5h5v5h5V58h5V34h-5v-5h-5v-5h-5v-5h-10v-5h-14V9H78zm-5 25V39H63v-5h10zm73 9V48h-5v-5h5z"/><path fill="#8e4637" d="M73 16.5V19h9v5h15v5h20v5h9v5h10v4h5v5h5v-5h-5v-9h-5v-5h-5v-5h-10v-5H97v-5H73z"/>`,
  // hair/spike .svg — spiky mohawk-style; template: #55271c / #804e39
  spike:
    `<path d="M103 2.5V5h-5v5h-5v5h-5v5h-5v5h-5v4h5v5h5v5h10v5h5v5h5v4h9V0h-4v49h-5v-5h-5v-5h-5v-5H88v-5h-5v-4h5v-5h5v-5h5v-5h5V5h5V0h-5z"/><path fill="#804e39" d="M108 2.5V5h-5v5h-5v5h-5v5h-5v5h-5v4h5v5h5v-5h5v-4h5v-5h5v-5h5V0h-5z"/><path fill="#55271c" d="M108 17.5V20h-5v5h-5v4h-5v5h5v5h5v5h5v5h5V15h-5z"/>`,
  // hair/usual.svg — structured quiff; template: #556a6a (single, both dark+mid)
  usual:
    `<g stroke-width="0"><path d="M102 2.5V5h-5v5H73v5H58v5h-5v5h-5v5h-5v44h5v4h10V49h5v-5h15v5h14v5h25v5h19v5h5v14h10V59h5V44h4V20h-4v-5h-5v-5h-5V5h-5V0h-39zm39 5V10h5v5h5v5h5v24h-5v15h-5v5h-5v-5h-5v-5h-19v-5H92v-5H78v-5H63v5h-5v5h-5v25h-5V30h5v-5h5v-5h15v-5h24v-5h5V5h39z"/><path d="M131 12.5V15h5v10h5v10h-5v9h-5v5h5v-5h5v-9h5V25h-5V15h-5v-5h-5zm-10 5V20h5v10h-5v9h-4v5h4v-5h5v-9h5V20h-5v-5h-5z"/></g><path fill="#556a6a" d="M102 7.5V10h-5v5H73v5H58v5h-5v5h-5v44h5V49h5v-5h5v-5h15v5h14v5h25v5h19v5h5v5h5v-5h5V44h5V20h-5v-5h-5v-5h-5V5h-39zm34 5V15h5v10h5v10h-5v9h-5v5h-5v-5h5v-9h5V25h-5V15h-5v-5h5zm-10 5V20h5v10h-5v9h-5v5h-4v-5h4v-9h5V20h-5v-5h5z"/>`,
};

// ─── Beard / Facial hair ──────────────────────────────────────────────────────
// Beard fill colors (#747480, #717489) are replaced with player's hair color.
// Skin-visible areas (#b98b**) are replaced by applySkin in the compositor.

const BEARD_LAYERS = {
  // beard/normal.svg — stubble / light beard
  stubble:
    `<g stroke-width="0"><path d="M58 114v12h5v5h5v5h5v5h5v5h5v4h33v-4h5v-5h5v-5h5v-5h5v-5h4v-24h-4v24h-5v5h-5v5h-5v5h-5v5H83v-5h-5v-5h-5v-5h-5v-5h-5v-24h-5z"/><path d="M87 119.5v2.5h25v-5H87z"/></g><path fill="#717489" d="M63 114v12h5v5h5v5h5v5h5v5h33v-5h5v-5h5v-5h5v-5h5v-24h-5v5h-5v5h-10v-5H83v5H73v-5h-5v-5h-5zm49 5.5v2.5H87v-5h25z"/>`,
  // beard/mustc.svg — moustache
  moustache:
    `<path d="M83 109.5v2.5h-5v14h5v-4h5v-5h24v5h5v4h4v-14h-4v-5H83z"/><path fill="#b98b4c" d="M88 119.5v2.5h24v-5H88z"/>`,
  // beard/french.svg — goatee / chin strip
  goatee:
    `<path d="M83 109.5v2.5h-5v15h5v-5h5v-5h24v5h5v5h4v-15h-4v-5H83zM93 129v2h5v5H88v-5H78v15h5v4h34v-4h4v-15h-9v5h-10v-5h5v-4H93z"/><path fill="#b98b4c" d="M88 119.5v2.5h24v-5H88z"/>`,
  // beard/beard.svg — full beard
  full_beard:
    `<g stroke-width="0"><path d="M58 114v12h5v5h5v5h5v5h5v5h5v4h33v-4h5v-5h5v-5h5v-5h5v-5h4v-24h-4v24h-5v5h-5v5h-5v5h-5v5H83v-5h-5v-5h-5v-5h-5v-5h-5v-24h-5z"/><path d="M112 114.5v2.5H87v5h25v-5h4v-5h-4z"/></g><path fill="#747480" d="M63 114v12h5v5h5v5h5v5h5v5h33v-5h5v-5h5v-5h5v-5h5v-24h-5v5h-5v5h-10v-5H83v5H73v-5h-5v-5h-5zm53 .5v2.5h-4v5H87v-5h25v-5h4z"/>`,
  // beard/fench_2.svg — goatee variant with golden lip highlight
  fench_2:
    `<path d="M83 109.5v2.5h-5v10h5v-5h15v-10H83zm19 2.5v5h15v5h5v-10h-5v-5h-15zm-34 24v5h10v5h5v5h5v4h24v-4h5v-5h5v-5h9v-10h-4v5h-15v5h-10v-5h5v-5H93v5h5v5H88v-5H73v-5h-5z"/><path fill="#c69240" d="M88 119.5v2.5h24v-5H88z"/>`,
  // beard/french_smile.svg — goatee with chin beard, smiling lip
  french_smile:
    `<path d="M93 109.5v2.5H83v5h-5v10h5v-5h5v-5h24v5h5v5h4v-10h-4v-5h-10v-5H93zm0 19.5v2h5v5H88v-5h-5v19h34v-19h-5v5h-10v-5h5v-4H93z"/><path fill="#b98b4c" d="M88 119.5v2.5h24v-5H88z"/>`,
};

// ─── Accessories ──────────────────────────────────────────────────────────────

const ACCESSORY_LAYERS = {
  // accessories/glass3.svg — blue tinted glasses
  glasses_3:
    `<path d="M53 68v5h10v14h34V73h5v14h34V73h9V63H53zm39 7.5V83H68V68h24zm39 0V83h-24V68h24z"/><path fill="#74a2cc" d="M68 70.5V73h24v-5H68zm39 0V73h24v-5h-24z"/><path fill="#a2b9cc" d="M68 75.5V78h24v-5H68zm39 0V78h24v-5h-24z"/><path fill="#d1d1ff" d="M68 80.5V83h24v-5H68zm39 0V83h24v-5h-24z"/>`,
  // accessories/glass4.svg — dark tinted glasses
  glasses_4:
    `<path d="M53 68v5h10v10h5v4h24v-4h5V73h5v10h5v4h24v-4h5V73h9V63H53zm39 7.5V83H68V68h24zm39 0V83h-24V68h24z"/><path fill="#464633" d="M68 70.5V73h24v-5H68zm39 0V73h24v-5h-24z"/><path fill="#747466" d="M68 75.5V78h24v-5H68zm39 0V78h24v-5h-24z"/><path fill="#a2a299" d="M68 80.5V83h24v-5H68zm39 0V83h24v-5h-24z"/>`,
  // accessories/tatto1.svg — face tattoo (right cheek, tall)
  f_tattoo_1:
    `<path d="M117 158.5v2.5h-5v5h5v9h5v-9h4v-5h-4v-5h-5z"/>`,
  // accessories/tatto2.svg — face tattoo (right cheek, short)
  f_tattoo_2:
    `<path d="M117 158.5v2.5h-5v5h5v4h5v-4h4v-5h-4v-5h-5z"/>`,
  // accessories/tatto3.svg — neck tattoo (left side)
  n_tattoo_1:
    `<path d="M83 153.5v2.5h-5v5h5v5h-5v5h5v4h5v-4h4v-5h-4v-5h4v-5h-4v-5h-5z"/>`,
  // accessories/tatto4.svg — neck tattoo (horizontal lines)
  n_tattoo_2:
    `<path d="M78 158.5v2.5h14v-5H78zm0 9.5v2h14v-4H78z"/>`,
};

// ─── Jersey (kit color injected at runtime) ───────────────────────────────────

/** Primary fill color in each jersey SVG — replaced with the club kit hex. */
const JERSEY_KIT_PLACEHOLDERS = {
  '1': '#464646',
  '2': '#464666',
  '3': '#005d66',
};

const JERSEY_LAYERS = {
  // jarsey/jarsey.svg — dark solid jersey
  '1':
    `<path d="M63 158.5v2.5H53v5h-9v5H34v5H24v4H14v5H9v14h5v-14h10v-5h10v-4h10v-5h9v-5h10v-5h5v-5h-5zm68 0v2.5h5v5h10v5h9v5h10v4h10v5h10v14h4v-14h-4v-5h-10v-4h-10v-5h-10v-5h-9v-5h-10v-5h-5z"/><path fill="#464646" d="M63 163.5v2.5H53v5h-9v5H34v4H24v5H14v14h171v-14h-10v-5h-10v-4h-10v-5h-9v-5h-10v-5h-5v15h-5v4h-5v5h-9v5H87v-5h-9v-5h-5v-4h-5v-15h-5z"/>`,
  // jarsey/jarsey1.svg — blue-dark jersey
  '2':
    `<path d="M63 158.5v2.5H53v5h-9v5H34v5H24v4H14v5H9v14h5v-14h10v-5h10v-4h10v-5h9v-5h10v-5h5v-5h-5zm68 0v2.5h5v5h10v5h9v5h10v4h10v5h10v14h4v-14h-4v-5h-10v-4h-10v-5h-10v-5h-9v-5h-10v-5h-5z"/><path fill="#464666" d="M63 163.5v2.5H53v5h-9v5H34v4H24v5H14v14h171v-14h-10v-5h-10v-4h-10v-5h-9v-5h-10v-5h-5v15h-5v4h-5v5h-9v5H87v-5h-9v-5h-5v-4h-5v-15h-5z"/>`,
  // jarsey/jarsey3.svg — teal jersey
  '3':
    `<path d="M63 158.5v2.5H53v5h-9v5H34v5H24v4H14v5H9v14h5v-14h10v-5h10v-4h10v-5h9v-5h10v-5h5v-5h-5zm68 0v2.5h5v5h10v5h9v5h10v4h10v5h10v14h4v-14h-4v-5h-10v-4h-10v-5h-10v-5h-9v-5h-10v-5h-5z"/><path fill="#005d66" d="M63 163.5v2.5H53v5h-9v5H34v4H24v5H14v14h171v-14h-10v-5h-10v-4h-10v-5h-9v-5h-10v-5h-5v15h-5v4h-5v5h-9v5H87v-5h-9v-5h-5v-4h-5v-15h-5z"/>`,
};

// ─── Skin tone palettes ───────────────────────────────────────────────────────
// Maps the placeholder colors baked into face SVG assets to per-skin-tone values.
//   #ffdbbf = faceLight (lit frontal face)
//   #e3b680 = faceMid   (cheek / shadow area)
//   #e3b992 = baldMid   (bald scalp mid-tone)
//   #ffd1c8 = baldLight (bald scalp highlight)
//   #b98b** = noseDeep  (nose / chin deep shadows — any #b98b hex variant)

// '#e8c49a' is the identity entry — its faceLight/faceMid match the SVG placeholders exactly.
const SKIN_PALETTES = {
  '#f5dcc8': { faceLight: '#fff5f0', faceMid: '#f0d4be', baldMid: '#edd0b0', baldLight: '#fff8f5', noseDeep: '#d4b090' },
  '#e8c49a': { faceLight: '#ffdbbf', faceMid: '#e3b680', baldMid: '#e3b992', baldLight: '#ffd1c8', noseDeep: '#b98b66' },
  '#dfaa80': { faceLight: '#f5c8b0', faceMid: '#dfaa80', baldMid: '#d4a870', baldLight: '#f0c4a0', noseDeep: '#a87855' },
  '#c47d4a': { faceLight: '#e09870', faceMid: '#c47d4a', baldMid: '#b87040', baldLight: '#e09060', noseDeep: '#906040' },
  '#8b4c1e': { faceLight: '#b07050', faceMid: '#8b4c1e', baldMid: '#804818', baldLight: '#b06848', noseDeep: '#683015' },
  '#5c2d0a': { faceLight: '#804838', faceMid: '#5c2d0a', baldMid: '#502808', baldLight: '#804040', noseDeep: '#401808' },
};

function resolvePalette(skinHex) {
  const hex = skinHex.toLowerCase();
  if (SKIN_PALETTES[hex]) return SKIN_PALETTES[hex];

  const r = parseInt(hex.slice(1, 3), 16) || 0;
  const g = parseInt(hex.slice(3, 5), 16) || 0;
  const b = parseInt(hex.slice(5, 7), 16) || 0;
  const lum = 0.299 * r + 0.587 * g + 0.114 * b;

  const keys = Object.keys(SKIN_PALETTES);
  let closest = keys[1]; // default to '#e8c49a' (SVG baseline)
  let minDist = Infinity;
  for (const k of keys) {
    const kr = parseInt(k.slice(1, 3), 16);
    const kg = parseInt(k.slice(3, 5), 16);
    const kb = parseInt(k.slice(5, 7), 16);
    const kl = 0.299 * kr + 0.587 * kg + 0.114 * kb;
    const d = Math.abs(lum - kl);
    if (d < minDist) { minDist = d; closest = k; }
  }
  return SKIN_PALETTES[closest];
}

function applySkin(content, p) {
  return content
    .replace(/#ffdbbf/gi, p.faceLight)
    .replace(/#e3b680/gi, p.faceMid)
    .replace(/#e3b992/gi, p.baldMid)
    .replace(/#ffd1c8/gi, p.baldLight)
    .replace(/#b98b[0-9a-f]{2}/gi, p.noseDeep);
}

// ─── Hair color map ───────────────────────────────────────────────────────────

const HAIR_HEX = {
  black:       '#1a1a1a',
  dark_brown:  '#3b1f28',
  brown:       '#796a45',
  light_brown: '#b58143',
  blonde:      '#d6b370',
};

// Per hair style: [templateDark, templateMid] to replace with palette colors.
// null = no baked fills; wrap the whole content in a solid fill group.
const HAIR_TEMPLATES = {
  bald:    null,           // skin layer — no hair color needed
  classic: ['#552e1a', '#8e4633'],
  messy:   ['#712400', '#8e4920'],
  round:   null,           // outline only — wrap in fill group
  smart:   ['#552e24', '#8e4637'],
  spike:   ['#55271c', '#804e39'],
  usual:   ['#556a6a', '#556a6a'],
};

// Two-shade hair palette per color: dark (shadow/depth) + mid (highlight/base)
const HAIR_PALETTES = {
  black:       { dark: '#111111', mid: '#333333' },
  dark_brown:  { dark: '#2a1206', mid: '#5a2e14' },
  brown:       { dark: '#552e1a', mid: '#8e4633' },
  light_brown: { dark: '#7a4820', mid: '#b07848' },
  blonde:      { dark: '#9a7820', mid: '#c8a848' },
};

function applyHairColor(svgContent, style, color) {
  const palette = HAIR_PALETTES[color] ?? HAIR_PALETTES.brown;
  const template = HAIR_TEMPLATES[style];

  if (!template) {
    return `<g fill="${palette.dark}">${svgContent}</g>`;
  }

  const [tDark, tMid] = template;
  let result = svgContent.replace(new RegExp(tDark, 'gi'), palette.dark);
  if (tDark !== tMid) {
    result = result.replace(new RegExp(tMid, 'gi'), palette.mid);
  }
  return result;
}

// ─── Jersey color ─────────────────────────────────────────────────────────────

function applyJerseyColor(content, variant, kitHex) {
  const placeholder = JERSEY_KIT_PLACEHOLDERS[variant];
  if (!placeholder) return `<g fill="${kitHex}">${content}</g>`;
  return content.replace(new RegExp(placeholder, 'gi'), kitHex);
}

// ─── Morale → eye / mouth key ─────────────────────────────────────────────────

function eyeKeyForMorale(morale, eyeShape) {
  if (morale >= 75) return 'happy';
  if (morale >= 50) return 'content';
  if (morale >= 35) return eyeShape === 'round' ? 'lean1' : 'neutral';
  if (morale >= 20) return 'low';
  return 'dejected';
}

function mouthKeyForMorale(morale) {
  if (morale >= 75) return 'happy';
  if (morale >= 50) return 'content';
  if (morale >= 35) return 'normal';
  if (morale >= 20) return 'low';
  return 'dejected';
}

// ─── Facial hair → layer key ──────────────────────────────────────────────────

const BEARD_KEY = {
  none:         null,
  stubble:      'stubble',
  moustache:    'moustache',
  goatee:       'goatee',
  beard:        'full_beard',
  fench_2:      'fench_2',
  french_smile: 'french_smile',
};

// ─── Public compositor ────────────────────────────────────────────────────────

/**
 * Compose all avatar layers into a single SVG XML string.
 * Render order (bottom → top):
 *   bald scalp → face → nose → mouth → eyes → hair → beard → accessories → jersey
 */
function composeAvatarSvg(appearance, morale, kitHex, size) {
  const skinTone   = appearance?.skinTone   ?? '#e8c49a';
  const hairStyle  = appearance?.hairStyle  ?? 'classic';
  const hairColor  = appearance?.hairColor  ?? 'brown';
  const accessory  = appearance?.accessory  ?? null;
  const facialHair = appearance?.facialHair ?? 'none';
  const noseType   = appearance?.noseType   ?? 'normal';
  const jerseyVar  = String(appearance?.jerseyVariant ?? 1);
  const faceShape  = appearance?.faceShape  ?? 'oval';

  const palette = resolvePalette(skinTone);
  const layers = [];

  // 1. Bald scalp — rendered behind the face so forehead outline sits on top
  if (hairStyle === 'bald') {
    const baldContent = HAIR_LAYERS.bald;
    if (baldContent) layers.push(applySkin(baldContent, palette));
  }

  // 2. Face (shape variant, skin tone applied)
  const faceContent = FACE_LAYERS[faceShape] ?? FACE_LAYERS.oval;
  layers.push(applySkin(faceContent, palette));

  // 3. Nose (skin tone applied)
  const noseContent = NOSE_LAYERS[noseType] ?? NOSE_LAYERS.normal;
  layers.push(applySkin(noseContent, palette));

  // 4. Mouth (morale-driven)
  const mouthKey = mouthKeyForMorale(morale);
  layers.push(MOUTH_LAYERS[mouthKey] ?? MOUTH_LAYERS.normal);

  // 5. Eyes (morale-driven; lean1 used for round eyeShape at neutral morale)
  const eyeKey = eyeKeyForMorale(morale, appearance?.eyeShape);
  layers.push(EYE_LAYERS[eyeKey] ?? EYE_LAYERS.neutral);

  // 6. Hair — on top of face, skipped for bald
  if (hairStyle !== 'bald') {
    const hairContent = HAIR_LAYERS[hairStyle] ?? HAIR_LAYERS.classic;
    layers.push(applyHairColor(hairContent, hairStyle, hairColor));
  }

  // 7. Beard (beard fill → hair color; skin areas → skin palette)
  const beardKey = BEARD_KEY[facialHair];
  if (beardKey) {
    const beardContent = BEARD_LAYERS[beardKey];
    if (beardContent) {
      const hairHex = HAIR_HEX[hairColor] ?? HAIR_HEX.brown;
      const colored = beardContent
        .replace(/#747480/gi, hairHex)
        .replace(/#717489/gi, hairHex);
      layers.push(applySkin(colored, palette));
    }
  }

  // 8. Accessories
  const GLASSES_VARIANTS = [
    ACCESSORY_LAYERS.glasses_3,
    ACCESSORY_LAYERS.glasses_4,
  ];
  const FACE_TATTOO_VARIANTS = [
    ACCESSORY_LAYERS.f_tattoo_1,
    ACCESSORY_LAYERS.f_tattoo_2,
  ];
  const NECK_TATTOO_VARIANTS = [
    ACCESSORY_LAYERS.n_tattoo_1,
    ACCESSORY_LAYERS.n_tattoo_2,
  ];

  // Stable deterministic index derived from kitTrim hash
  const kitSeed = Math.abs(
    (appearance?.kitTrim ?? '').split('').reduce((h, c) => (h << 5) + h ^ c.charCodeAt(0), 5381),
  );

  if (accessory === 'glasses' || accessory === 'sunglasses') {
    layers.push(GLASSES_VARIANTS[kitSeed % GLASSES_VARIANTS.length] ?? GLASSES_VARIANTS[0]);
  } else if (accessory === 'face_tattoo') {
    layers.push(FACE_TATTOO_VARIANTS[kitSeed % FACE_TATTOO_VARIANTS.length] ?? FACE_TATTOO_VARIANTS[0]);
  } else if (accessory === 'neck_tattoo') {
    layers.push(NECK_TATTOO_VARIANTS[kitSeed % NECK_TATTOO_VARIANTS.length] ?? NECK_TATTOO_VARIANTS[0]);
  }

  // 9. Jersey (kit color applied)
  const jerseyContent = JERSEY_LAYERS[jerseyVar] ?? JERSEY_LAYERS['1'];
  layers.push(applyJerseyColor(jerseyContent, jerseyVar, kitHex));

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="${size}" height="${size}">\n${layers.join('\n')}\n</svg>`;
}

window.composeAvatarSvg = composeAvatarSvg;
