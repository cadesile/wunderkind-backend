/**
 * Small admin-wide UI corrections, loaded on every EasyAdmin page via
 * DashboardController::configureAssets().
 *
 * Currently one thing: making the sidebar collapse control usable.
 *
 * EasyAdmin renders that control as a bare <div id="sidebar-resizer-handler">
 * with a resize cursor and no label, yet clicking it toggles the whole nav
 * between its full and 64px icon-rail widths (persisted in localStorage under
 * `ea/sidebar/width`). As shipped it is invisible, unreachable by keyboard and
 * unannounced to assistive tech. admin-theme.css gives it a visible button
 * face; this gives it the semantics to match.
 *
 * The toggle behaviour itself stays EasyAdmin's — this never calls the width
 * logic directly, it only labels what EasyAdmin already does. That way an
 * upstream change to how the width is stored cannot leave us driving it wrong.
 */
(function () {
    'use strict';

    var handler = document.getElementById('sidebar-resizer-handler');
    if (!handler) { return; }

    var COMPACT_CLASS = 'ea-sidebar-width-compact';

    function isCompact() {
        return document.body.classList.contains(COMPACT_CLASS);
    }

    function label() {
        var text = isCompact() ? 'Expand sidebar' : 'Collapse sidebar';
        handler.setAttribute('aria-label', text);
        handler.setAttribute('aria-expanded', isCompact() ? 'false' : 'true');
        handler.setAttribute('title', text);
    }

    handler.setAttribute('role', 'button');
    handler.setAttribute('tabindex', '0');
    label();

    // EasyAdmin's own listener runs on the same click and flips the body class.
    // Re-read the state on the next tick rather than predicting it, so the label
    // stays correct even if that logic changes.
    handler.addEventListener('click', function () {
        window.setTimeout(label, 0);
    });

    // A div gets no keyboard activation for free. Enter and Space are what a
    // real button would respond to.
    handler.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'Spacebar') { return; }
        event.preventDefault();
        handler.click();
    });
}());
