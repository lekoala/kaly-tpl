<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Strict parser for `srcset` and `imagesrcset` values.
 *
 * It follows the WHATWG candidate tokenization: a URL is a run of non-whitespace
 * characters, so it may contain commas, and a trailing comma ends the candidate.
 * Unlike browsers, which silently skip what they cannot parse, it rejects empty
 * candidates, several descriptors on one candidate, unknown or duplicate descriptors,
 * and width descriptors mixed with density descriptors.
 *
 * @internal
 */
final class SrcsetParser
{
    private const WHITESPACE = " \t\n\f\r";

    /**
     * @return list<array{url: string, descriptor: ?string}>
     */
    public static function parse(string $value): array
    {
        $candidates = [];
        $length = strlen($value);
        $position = strspn($value, self::WHITESPACE);

        while ($position < $length) {
            $urlLength = strcspn($value, self::WHITESPACE, $position);
            $url = substr($value, $position, $urlLength);
            $position += $urlLength;
            $descriptor = null;
            $separated = str_ends_with($url, ',');

            if ($separated) {
                $url = substr($url, 0, -1);
            } else {
                $position += strspn($value, self::WHITESPACE, $position);
                $descriptorLength = strcspn($value, self::WHITESPACE . ',', $position);
                if ($descriptorLength > 0) {
                    $descriptor = substr($value, $position, $descriptorLength);
                    $position += $descriptorLength;
                    $position += strspn($value, self::WHITESPACE, $position);
                }

                if ($position < $length) {
                    if ($value[$position] !== ',') {
                        throw self::invalid($value, 'a candidate takes at most one descriptor');
                    }
                    $position++;
                    $separated = true;
                }
            }

            if ($url === '' || str_starts_with($url, ',') || str_ends_with($url, ',')) {
                throw self::invalid($value, 'empty image candidate');
            }

            $candidates[] = ['url' => $url, 'descriptor' => $descriptor];
            $position += strspn($value, self::WHITESPACE, $position);

            if ($separated && $position >= $length) {
                throw self::invalid($value, 'empty image candidate');
            }
        }

        self::assertDescriptors($candidates, $value);

        return $candidates;
    }

    /** @param list<array{url: string, descriptor: ?string}> $candidates */
    private static function assertDescriptors(array $candidates, string $value): void
    {
        $seen = [];

        foreach ($candidates as $candidate) {
            // A candidate without a descriptor is the same as `1x`.
            $descriptor = $candidate['descriptor'] ?? '1x';
            // HTML microsyntaxes: a width is a non-negative integer (leading zeros allowed), a
            // density a floating-point number (exponent allowed). Values are normalized for duplicates.
            if (preg_match('/^(\d+)w$/', $descriptor, $match) && (int) $match[1] > 0) {
                $key = 'w' . (int) $match[1];
            } elseif (
                preg_match('/^((?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)x$/', $descriptor, $match)
                && (float) $match[1] > 0
                && is_finite((float) $match[1])
            ) {
                $key = 'x' . (float) $match[1];
            } else {
                throw self::invalid($value, sprintf('unknown descriptor "%s"', $descriptor));
            }

            if (array_key_exists($key, $seen)) {
                throw self::invalid($value, sprintf('duplicate descriptor "%s"', $descriptor));
            }
            $seen[$key] = $key[0];
        }

        if (count(array_unique($seen)) > 1) {
            throw self::invalid($value, 'width and density descriptors cannot be mixed');
        }
    }

    private static function invalid(string $value, string $reason): ViewUsageException
    {
        return new ViewUsageException(sprintf('Invalid srcset "%s": %s.', $value, $reason));
    }
}
