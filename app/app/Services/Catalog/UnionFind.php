<?php

namespace App\Services\Catalog;

final class UnionFind
{
    /** @var array<string, string> */
    private array $parent = [];

    /** @var array<string, int> */
    private array $rank = [];

    /** @param  list<string>  $ids */
    public function __construct(array $ids)
    {
        foreach (array_unique($ids) as $id) {
            $this->parent[$id] = $id;
            $this->rank[$id] = 0;
        }
    }

    public function union(string $a, string $b): void
    {
        $a = $this->find($a);
        $b = $this->find($b);
        if ($a === $b) {
            return;
        }
        if ($this->rank[$a] < $this->rank[$b]) {
            [$a, $b] = [$b, $a];
        }
        $this->parent[$b] = $a;
        if ($this->rank[$a] === $this->rank[$b]) {
            $this->rank[$a]++;
        }
    }

    /** @return list<list<string>> clusters sorted by smallest member */
    public function groups(): array
    {
        $groups = [];
        foreach (array_keys($this->parent) as $id) {
            $groups[$this->find($id)][] = $id;
        }
        foreach ($groups as &$ids) {
            sort($ids);
        }
        unset($ids);
        usort($groups, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_values($groups);
    }

    private function find(string $id): string
    {
        if ($this->parent[$id] !== $id) {
            $this->parent[$id] = $this->find($this->parent[$id]);
        }

        return $this->parent[$id];
    }
}
