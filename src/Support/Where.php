<?php

declare(strict_types=1);

namespace App\Support;

/** Baut WHERE-Klauseln mit gebundenen Parametern (Spaltennamen kommen nur aus Code, nie aus Eingaben). */
final class Where
{
    /** @var list<string> */
    private array $parts = [];
    /** @var list<mixed> */
    private array $params = [];

    public function eq(string $column, ?string $value): self
    {
        if ($value !== null) {
            $this->parts[] = "$column = ?";
            $this->params[] = $value;
        }
        return $this;
    }

    /** @param list<string> $columns */
    public function search(array $columns, ?string $term): self
    {
        if ($term !== null) {
            $likes = array_map(static fn ($c) => "$c LIKE ? ESCAPE '\\'", $columns);
            $this->parts[] = '(' . implode(' OR ', $likes) . ')';
            foreach ($columns as $_) {
                $this->params[] = Db::like($term);
            }
        }
        return $this;
    }

    public function raw(string $sql, array $params = []): self
    {
        $this->parts[] = $sql;
        array_push($this->params, ...$params);
        return $this;
    }

    public function sql(): string
    {
        return $this->parts === [] ? '' : ' WHERE ' . implode(' AND ', $this->parts);
    }

    /** @return list<mixed> */
    public function params(): array
    {
        return $this->params;
    }
}
