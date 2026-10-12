<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

/** @template T */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(public array $items, public string|int|null $nextCursor, public int $limit) {}

    public static function fromArray(array $data, callable $decode, string $cursorKey = 'next_cursor'): self
    {
        $rows = Decode::array($data, 'data');
        $meta = Decode::array($data, 'meta');
        $cursor = $meta[$cursorKey] ?? null;
        if (! array_is_list($rows) || count($rows) > 50 || ($cursor !== null && ! is_string($cursor) && ! is_int($cursor))) {
            throw new ApiException(502, 'invalid_response');
        }
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ApiException(502, 'invalid_response');
            }
            $items[] = $decode($row);
        }

        return new self($items, $cursor, Decode::int($meta, 'limit'));
    }
}
