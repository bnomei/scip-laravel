<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Blade;

use Bnomei\ScipLaravel\Support\SourceRangeFactory;

use function array_values;
use function preg_match_all;
use function stripcslashes;
use function strlen;

final class BladeReferenceScanner
{
    private const PATTERN = '/@(?<directive>extends|include|lang)\s*\(\s*(?<quote>[\'"])(?<literal>(?:\\\\.|(?!\k<quote>).)*)\k<quote>/m';

    public function __construct(
        private readonly SourceRangeFactory $rangeFactory = new SourceRangeFactory(),
    ) {}

    /**
     * @return list<BladeDirectiveReference>
     */
    public function scan(string $contents): array
    {
        $matches = [];
        preg_match_all(self::PATTERN, $contents, $matches, PREG_OFFSET_CAPTURE);

        $references = [];
        $count = count($matches['directive']);

        for ($index = 0; $index < $count; $index++) {
            $directive = $matches['directive'][$index][0];
            $literal = $matches['literal'][$index][0];
            $literalOffset = $matches['literal'][$index][1];

            if ($directive === '' || $literal === '' || $literalOffset < 0) {
                continue;
            }

            $references[] = new BladeDirectiveReference(
                directive: $directive,
                type: $directive === 'lang' ? 'translation' : 'view',
                literal: stripcslashes($literal),
                range: $this->rangeFactory->fromOffsets($contents, $literalOffset, $literalOffset + strlen($literal)),
            );
        }

        return $references;
    }

    /**
     * @return list<BladeDirectiveReference>
     */
    public function scanTranslations(string $contents): array
    {
        return array_values(array_filter(
            $this->scan($contents),
            static fn(BladeDirectiveReference $reference): bool => $reference->type === 'translation',
        ));
    }

    /**
     * @return list<BladeDirectiveReference>
     */
    public function scanViews(string $contents): array
    {
        return array_values(array_filter(
            $this->scan($contents),
            static fn(BladeDirectiveReference $reference): bool => $reference->type === 'view',
        ));
    }
}
