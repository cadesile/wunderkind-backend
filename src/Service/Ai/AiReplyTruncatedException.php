<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * The model ran out of output budget before it finished answering.
 *
 * A SUBTYPE BECAUSE THE REMEDY DIFFERS, not for tidiness. Every other way of not getting an
 * answer — a timeout, a 529, a body that is not JSON — may well succeed if asked again, so
 * retrying is right. Truncation will not: the same prompt against the same ceiling truncates
 * at the same place every time, so a retry is three billed calls to learn what the first one
 * already said. The fix is a smaller ask or a bigger budget, and only a human can choose.
 *
 * Extending AiUnavailableException is safe here in a way the enrichment note on that class
 * warns against: nothing catches THIS type yet, so adding it cannot stop an existing catch
 * site from working, and every `catch (AiUnavailableException)` keeps behaving as before.
 * Callers that want to stop retrying opt in by catching this first.
 */
class AiReplyTruncatedException extends AiUnavailableException
{
}
