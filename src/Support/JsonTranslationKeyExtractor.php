<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use JsonException;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function strlen;

final class JsonTranslationKeyExtractor
{
    /**
     * @return list<JsonTranslationKey>
     */
    public function extract(string $filePath): array
    {
        $contents = file_get_contents($filePath);

        if (!is_string($contents) || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $offsets = $this->topLevelKeyOffsets($contents);
        $definitions = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key) || $key === '' || !array_key_exists($key, $offsets)) {
                continue;
            }

            [$start, $end] = $offsets[$key];

            if (!is_int($start) || !is_int($end)) {
                continue;
            }

            $definitions[] = new JsonTranslationKey(
                filePath: $filePath,
                key: $key,
                range: SourceRange::fromOffsets($contents, $start, $end),
            );
        }

        return $definitions;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function topLevelKeyOffsets(string $contents): array
    {
        $length = strlen($contents);
        $offset = $this->skipWhitespace($contents, 0);

        if (($contents[$offset] ?? null) !== '{') {
            return [];
        }

        $offset++;
        $keys = [];

        while ($offset < $length) {
            $offset = $this->skipWhitespace($contents, $offset);
            $char = $contents[$offset] ?? null;

            if ($char === '}') {
                return $keys;
            }

            if ($char !== '"') {
                return [];
            }

            [$rawKey, $start, $end, $offset] = $this->parseStringToken($contents, $offset);
            $decodedKey = json_decode('"' . $rawKey . '"');

            if (is_string($decodedKey) && !array_key_exists($decodedKey, $keys)) {
                $keys[$decodedKey] = [$start, $end];
            }

            $offset = $this->skipWhitespace($contents, $offset);

            if (($contents[$offset] ?? null) !== ':') {
                return [];
            }

            $offset = $this->skipValue($contents, $offset + 1);
            $offset = $this->skipWhitespace($contents, $offset);
            $char = $contents[$offset] ?? null;

            if ($char === ',') {
                $offset++;
                continue;
            }

            if ($char === '}') {
                return $keys;
            }

            return [];
        }

        return [];
    }

    /**
     * @return array{string, int, int, int}
     */
    private function parseStringToken(string $contents, int $offset): array
    {
        $length = strlen($contents);
        $raw = '';
        $start = $offset + 1;
        $offset++;

        while ($offset < $length) {
            $char = $contents[$offset];

            if ($char === '\\') {
                $raw .= $char;
                $offset++;

                if ($offset < $length) {
                    $raw .= $contents[$offset];
                    $offset++;
                }

                continue;
            }

            if ($char === '"') {
                return [$raw, $start, $offset, $offset + 1];
            }

            $raw .= $char;
            $offset++;
        }

        return [$raw, $start, $offset, $offset];
    }

    private function skipValue(string $contents, int $offset): int
    {
        $offset = $this->skipWhitespace($contents, $offset);
        $char = $contents[$offset] ?? null;

        if ($char === '"') {
            [, , , $offset] = $this->parseStringToken($contents, $offset);

            return $offset;
        }

        if ($char === '{') {
            return $this->skipDelimited($contents, $offset, '{', '}');
        }

        if ($char === '[') {
            return $this->skipDelimited($contents, $offset, '[', ']');
        }

        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if (
                $char === ','
                || $char === '}'
                || $char === ']'
                || $char === ' '
                || $char === "\t"
                || $char === "\n"
                || $char === "\r"
            ) {
                return $offset;
            }

            $offset++;
        }

        return $offset;
    }

    private function skipDelimited(string $contents, int $offset, string $open, string $close): int
    {
        $length = strlen($contents);
        $depth = 0;

        while ($offset < $length) {
            $char = $contents[$offset];

            if ($char === '"') {
                [, , , $offset] = $this->parseStringToken($contents, $offset);
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0) {
                    return $offset + 1;
                }
            }

            $offset++;
        }

        return $offset;
    }

    private function skipWhitespace(string $contents, int $offset): int
    {
        $length = strlen($contents);

        while ($offset < $length) {
            $char = $contents[$offset];

            if ($char !== ' ' && $char !== "\t" && $char !== "\n" && $char !== "\r") {
                return $offset;
            }

            $offset++;
        }

        return $offset;
    }
}
