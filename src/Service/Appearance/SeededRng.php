<?php
namespace App\Service\Appearance;

/**
 * Seeded LCG (Linear Congruential Generator) — faithful port of the frontend
 * SeededRng in wunderkind-app/src/engine/appearance.ts. All arithmetic is kept
 * to unsigned 32-bit via `& 0xFFFFFFFF` so output matches the JS `>>> 0` semantics.
 */
final class SeededRng
{
    private int $s;

    public function __construct(int $seed)
    {
        $this->s = $seed & 0xFFFFFFFF;
    }

    /** djb2-variant string hash → stable uint32 seed. */
    public static function hashId(string $id): int
    {
        $hash = 5381;
        $len  = strlen($id);
        for ($i = 0; $i < $len; $i++) {
            $hash = ((($hash << 5) + $hash) ^ ord($id[$i])) & 0xFFFFFFFF;
        }
        return $hash & 0xFFFFFFFF;
    }

    /** Float in [0, 1). */
    public function next(): float
    {
        $this->s = (($this->s * 1664525) + 1013904223) & 0xFFFFFFFF;
        return $this->s / 4294967296; // 0x100000000
    }

    public function pick(array $arr): mixed
    {
        return $arr[(int) floor($this->next() * count($arr))];
    }

    /**
     * Weighted pick over $arr using integer $weights (parallel, same length).
     * Consumes exactly one next() — same as pick() — so swapping one for the
     * other leaves every downstream draw in the stream untouched.
     *
     * @param list<mixed> $arr
     * @param list<int>   $weights
     */
    public function weightedPick(array $arr, array $weights): mixed
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return $this->pick($arr);
        }

        $roll = $this->next() * $total;
        $acc  = 0;
        foreach ($weights as $i => $weight) {
            $acc += $weight;
            if ($roll < $acc) {
                return $arr[$i];
            }
        }

        return $arr[array_key_last($arr)];
    }

    public function chance(float $probability): bool
    {
        return $this->next() < $probability;
    }
}
