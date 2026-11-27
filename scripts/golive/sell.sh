#!/usr/bin/env bash
# Go-live step 4: buy out every imported club in wk_golive, crediting its owner's account.
#
#   bash scripts/golive/sell.sh --dry-run     # report every figure, change nothing
#   bash scripts/golive/sell.sh               # queue in batches and drain the queue
#
# Uses the existing buyout (app:clubs:sell-batch -> SellClubMessageHandler -> ClubSaleService)
# with the clubsale-v1 formula as is, unreviewed, which is what was agreed for go-live. Each
# sale credits first and then PERMANENTLY deletes the club, so this runs only on wk_golive
# and only after a typed confirmation. Spoof clubs are never sold.
#
# Without GOLIVE_SALE_NOTE each credit reads "Club sale — <club name>" in the player's account
# history, which is what the app's one-off "we bought your club" notice shows. Set it only to
# override that text for every sale.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

NOTE_ARGS=()
[[ -n "${GOLIVE_SALE_NOTE:-}" ]] && NOTE_ARGS=(--note "$GOLIVE_SALE_NOTE")
BATCH="${GOLIVE_SALE_BATCH:-25}"

if [[ "${1:-}" == "--dry-run" ]]; then
    say "Dry run: every club, every figure, nothing queued"
    console_on "$GOLIVE_DB" app:clubs:sell-batch --all --dry-run --limit 100000
    exit 0
fi

confirm_word SELL "This pays every imported owner and then deletes their club in $GOLIVE_DB."

while :; do
    remaining="$(dev_psql "$GOLIVE_DB" -At -c "SELECT count(*) FROM club c WHERE NOT coalesce(c.is_spoof,false) AND NOT EXISTS (SELECT 1 FROM club_sale s WHERE s.club_id = c.id::text AND s.status <> 'failed')")"
    [[ "$remaining" == "0" ]] && break
    say "$remaining clubs left to queue; queuing $BATCH"
    console_on "$GOLIVE_DB" app:clubs:sell-batch --all --limit "$BATCH" "${NOTE_ARGS[@]}"
    say "Draining the clubsale queue"
    console_on "$GOLIVE_DB" messenger:consume clubsale --limit "$BATCH" --time-limit 600 --no-interaction -vv
    # A sale whose handler threw stays PENDING and its message moves to the failed transport,
    # so "pending with nothing left on the clubsale queue" is the failure signal.
    stuck="$(dev_psql "$GOLIVE_DB" -At -c "SELECT CASE WHEN (SELECT count(*) FROM messenger_messages WHERE queue_name = 'clubsale' AND delivered_at IS NULL) = 0 THEN (SELECT count(*) FROM club_sale WHERE status IN ('pending','failed')) ELSE 0 END")"
    [[ "$stuck" == "0" ]] || die "$stuck sales did not complete: run messenger:failed:show against $GOLIVE_DB. Stopping."
done

say "Draining anything still queued"
console_on "$GOLIVE_DB" messenger:consume clubsale --time-limit 120 --no-interaction

say "Verifying the buyout"
dev_psql "$GOLIVE_DB" < "$SCRIPT_DIR/sql/verify-sale.sql"
say "Done. wk_golive is ready to promote."
