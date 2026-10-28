<?php

namespace App\Services\Catalog;

use App\Data\NamedGroup;
use App\Data\SkuGroup;
use App\Support\Vec;

final class NameMerger
{
    public const MIN_SIMILARITY = 0.70;

    public const IDENTICAL_SIMILARITY = 0.55;

    public const MAX_LEVENSHTEIN = 2;

    /**
     * Merge groups whose normalized names are fuzzy-equal and whose
     * representative embeddings clear the similarity gate (0.70, or 0.55
     * for identical names via the fast path). A "brand unclear" read
     * carries no identity, so it merges on vision alone at 0.70.
     *
     * Name, confidence and extracted payload come from the highest-confidence
     * entry; crop, embedding and shelf position from the largest-crop member.
     *
     * @param  list<NamedGroup>  $entries
     * @return list<NamedGroup>
     */
    public function merge(array $entries, float $minSimilarity = self::MIN_SIMILARITY): array
    {
        usort($entries, fn (NamedGroup $a, NamedGroup $b) => $a->group->representativeId <=> $b->group->representativeId);
        $byId = [];
        $names = [];
        foreach ($entries as $entry) {
            $id = $entry->group->representativeId;
            $byId[$id] = $entry;
            $names[$id] = self::normalize($entry->name);
        }

        $union = new UnionFind(array_keys($byId));
        $reasons = [];
        $ids = array_keys($byId);
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            if ($names[$ids[$i]] === '') {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if ($names[$ids[$j]] === '') {
                    continue;
                }
                $nameReason = self::fuzzyReason($names[$ids[$i]], $names[$ids[$j]]);
                $identical = $names[$ids[$i]] === $names[$ids[$j]];
                $brandless = $nameReason === null
                    && (self::isBrandless($names[$ids[$i]]) || self::isBrandless($names[$ids[$j]]));
                if ($nameReason === null && ! $brandless) {
                    continue;
                }
                $gate = $identical ? self::IDENTICAL_SIMILARITY : $minSimilarity;
                $similarity = Vec::cosineSimilarity($byId[$ids[$i]]->group->embedding, $byId[$ids[$j]]->group->embedding);
                if ($similarity < $gate) {
                    continue;
                }
                $union->union($ids[$i], $ids[$j]);
                $reasons[$ids[$i].':'.$ids[$j]] = ($nameReason ?? 'brandless name defers to vision')
                    .', similarity '.round($similarity, 3).($identical ? ' (fast path)' : '');
            }
        }

        return array_map(
            fn (array $memberIds) => $this->merged(array_map(fn (string $id) => $byId[$id], $memberIds), $memberIds, $reasons),
            $union->groups()
        );
    }

    /** @param  list<NamedGroup>  $members */
    private function merged(array $members, array $memberIds, array $reasons): NamedGroup
    {
        $winner = $members[0];
        foreach ($members as $member) {
            if ($member->confidence > $winner->confidence) {
                $winner = $member;
            }
        }

        $groups = array_map(fn (NamedGroup $member) => $member->group, $members);
        usort($groups, fn (SkuGroup $a, SkuGroup $b) => [$b->cropWidth * $b->cropHeight, $a->representativeId]
            <=> [$a->cropWidth * $a->cropHeight, $b->representativeId]);
        $rep = $groups[0];

        $items = [];
        $facings = 0;
        foreach ($members as $member) {
            array_push($items, ...$member->group->items);
            $facings += $member->group->facings;
        }
        usort($items, fn ($a, $b) => $a->representativeId <=> $b->representativeId);

        $mergedFrom = [];
        $mergeReasons = [];
        foreach ($memberIds as $id) {
            if ($id === $winner->group->representativeId) {
                continue;
            }
            $mergedFrom[] = $id;
            $mergeReasons[] = $this->edgeReason($id, $reasons);
        }

        return new NamedGroup(
            group: new SkuGroup(
                representativeId: $rep->representativeId,
                cropPath: $rep->cropPath,
                embedding: $rep->embedding,
                facings: $facings,
                shelfRow: $rep->shelfRow,
                shelfOrder: $rep->shelfOrder,
                cropWidth: $rep->cropWidth,
                cropHeight: $rep->cropHeight,
                items: $items,
            ),
            name: $winner->name,
            confidence: $winner->confidence,
            extracted: $winner->extracted,
            matchedProductId: $winner->matchedProductId,
            matchDistance: $winner->matchDistance,
            mergedFrom: $mergedFrom,
            mergeReasons: $mergeReasons,
        );
    }

    private function edgeReason(string $id, array $reasons): string
    {
        foreach ($reasons as $edge => $reason) {
            [$a, $b] = explode(':', $edge, 2);
            if ($a === $id || $b === $id) {
                return $reason;
            }
        }

        return 'merged transitively';
    }

    private static function fuzzyReason(string $a, string $b): ?string
    {
        if ($a === $b) {
            return "identical names ('{$a}')";
        }
        $distance = levenshtein($a, $b);
        if ($distance <= self::MAX_LEVENSHTEIN) {
            return "levenshtein {$distance} ('{$a}'~'{$b}')";
        }
        if (self::contained($a, $b)) {
            $short = strlen($a) <= strlen($b) ? $a : $b;
            $long = $short === $a ? $b : $a;

            return "name contained ('{$short}' in '{$long}')";
        }

        return null;
    }

    private static function isBrandless(string $normalized): bool
    {
        return str_contains($normalized, 'brand unclear');
    }

    private static function contained(string $a, string $b): bool
    {
        $wordsA = explode(' ', $a);
        $wordsB = explode(' ', $b);
        [$short, $long] = count($wordsA) <= count($wordsB) ? [$wordsA, $wordsB] : [$wordsB, $wordsA];

        return $short !== [] && array_diff($short, $long) === [];
    }

    private static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9 ]+/u', '', $name);
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }
}
