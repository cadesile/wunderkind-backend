-- Go-live check after the club buyout has drained: every imported club was sold exactly
-- once, every owner was paid exactly what their sale row says, and nothing was stranded.
-- RAISEs on the first problem. Prints a summary at the end.

\set ON_ERROR_STOP on
BEGIN;

DO $$
DECLARE a bigint;
BEGIN
    IF current_database() <> 'wk_golive' THEN
        RAISE EXCEPTION 'verify-sale.sql must only run on wk_golive';
    END IF;

    SELECT count(*) INTO a FROM public.club;
    IF a <> 0 THEN RAISE EXCEPTION '% clubs are still standing (sale pending or failed)', a; END IF;

    SELECT count(*) INTO a FROM golive_export.club c
     WHERE NOT EXISTS (SELECT 1 FROM public.club_sale s
                        WHERE s.club_id = c.id::text AND s.status = 'completed');
    IF a <> 0 THEN RAISE EXCEPTION '% imported clubs have no completed sale', a; END IF;

    SELECT count(*) INTO a FROM (SELECT club_id FROM public.club_sale WHERE status = 'completed'
                                  GROUP BY club_id HAVING count(*) > 1) x;
    IF a <> 0 THEN RAISE EXCEPTION '% clubs were sold more than once', a; END IF;

    -- A non-zero sale has exactly one CLUB_SALE ledger row of the agreed amount.
    SELECT count(*) INTO a FROM public.club_sale s
      LEFT JOIN public.user_ledger l ON l.id = s.ledger_entry_id
     WHERE s.status = 'completed' AND s.agreed_pence > 0
       AND (l.id IS NULL OR l.type <> 'club_sale' OR l.amount_pence <> s.agreed_pence);
    IF a <> 0 THEN RAISE EXCEPTION '% sales lack a matching club_sale ledger entry', a; END IF;

    -- Balance now = balance before + everything the buyout paid. Nothing else ran in between.
    SELECT count(*) INTO a FROM golive_export.balance_before b
     WHERE b.balance_pence
         + coalesce((SELECT sum(s.agreed_pence) FROM public.club_sale s
                      WHERE s.user_id = b.user_id AND s.status = 'completed'), 0)
        <> coalesce((SELECT l.balance_after_pence FROM public.user_ledger l WHERE l.user_id = b.user_id
                      ORDER BY l.recorded_at DESC, l.id DESC LIMIT 1), 0);
    IF a <> 0 THEN RAISE EXCEPTION '% owners have a balance that is not before + sale', a; END IF;

    SELECT count(*) INTO a FROM public.plot_claim WHERE released_at IS NULL AND club_id IS NULL;
    IF a <> 0 THEN RAISE EXCEPTION '% stranded plot claims', a; END IF;
END $$;

SELECT count(*)                                  AS clubs_sold,
       sum(agreed_pence)                         AS paid_pence,
       min(agreed_pence)                         AS min_pence,
       percentile_disc(0.5) WITHIN GROUP (ORDER BY agreed_pence) AS median_pence,
       max(agreed_pence)                         AS max_pence,
       count(*) FILTER (WHERE agreed_pence = 0)  AS zero_payouts
  FROM public.club_sale WHERE status = 'completed';
COMMIT;
