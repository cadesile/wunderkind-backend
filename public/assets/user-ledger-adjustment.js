/**
 * Live balance preview for the admin's "New Adjustment" ledger form.
 *
 * Loaded only by templates/admin/user_ledger/create_adjustment.html.twig — a per-screen widget,
 * not an admin-wide asset, so it is absent from DashboardController::configureAssets().
 *
 * NO NETWORK CALLS. Every user's current balance is already rendered onto its <option> as
 * data-balance-pence by the controller (one DISTINCT ON query), so this is pure arithmetic over
 * values on the page. That removes an endpoint, its auth surface and every async failure mode.
 *
 * THE PREVIEW IS ADVISORY AND MUST STAY THAT WAY. The figures actually stored are read by
 * UserLedgerService::append() inside its own transaction, under a per-user advisory lock,
 * precisely so two concurrent writers cannot fork the balance chain. The two fields this
 * updates have no `name` attribute, so the browser cannot post them even if someone later
 * wanted them to.
 *
 * Parsing deliberately mirrors the server's rule (optionally-negative digits only). A value the
 * server will reject previews as "—" rather than as a number the operator might trust.
 */
(function () {
    'use strict';

    var form = document.getElementById('ledger-adjustment-form');
    if (!form) { return; }

    var userSelect = document.getElementById('userId');
    var amountInput = document.getElementById('amountPence');
    var beforeField = document.getElementById('balanceBeforeDisplay');
    var afterField = document.getElementById('balanceAfterDisplay');
    var amountPreview = document.getElementById('amountPreview');

    if (!userSelect || !amountInput || !beforeField || !afterField) { return; }

    var EM_DASH = '—';
    // Same shape as the server's preg_match, so the two agree on what counts as an amount.
    var AMOUNT_PATTERN = /^-?[0-9]{1,15}$/;

    /** Exact, never abbreviated — the whole job of this screen is confirming a precise figure. */
    function formatPence(pence) {
        var negative = pence < 0;
        var units = Math.abs(pence) / 100;

        return (negative ? '-' : '') + '£' + units.toLocaleString('en-GB', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function selectedBalancePence() {
        var option = userSelect.options[userSelect.selectedIndex];
        if (!option || !option.value) { return null; }

        var raw = option.getAttribute('data-balance-pence');
        // An account with no ledger rows yet is rendered as 0, matching what
        // getCurrentBalance() returns for it.
        var parsed = parseInt(raw === null || raw === '' ? '0' : raw, 10);

        return isNaN(parsed) ? 0 : parsed;
    }

    function parsedAmountPence() {
        var raw = amountInput.value.trim();
        if (!AMOUNT_PATTERN.test(raw)) { return null; }

        var parsed = parseInt(raw, 10);

        return parsed === 0 ? null : parsed;
    }

    function render() {
        var before = selectedBalancePence();
        var amount = parsedAmountPence();

        amountPreview.textContent = amount === null ? '' : '= ' + formatPence(amount);

        if (before === null) {
            beforeField.value = EM_DASH;
            afterField.value = EM_DASH;
            afterField.classList.remove('text-danger');
            return;
        }

        beforeField.value = formatPence(before);

        if (amount === null) {
            afterField.value = EM_DASH;
            afterField.classList.remove('text-danger');
            return;
        }

        var after = before + amount;
        afterField.value = formatPence(after);
        // A negative resulting balance is a legitimate state, not a refusal — the admin just
        // needs to see that it is what they are about to do.
        afterField.classList.toggle('text-danger', after < 0);
    }

    userSelect.addEventListener('change', render);
    amountInput.addEventListener('input', render);

    // Run once so a form re-rendered after a validation error shows the preview for the values
    // it kept, rather than two em-dashes under a filled-in form.
    render();
})();
