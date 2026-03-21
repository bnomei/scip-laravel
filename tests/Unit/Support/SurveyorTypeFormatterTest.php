<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\SurveyorTypeFormatter;
use Laravel\Surveyor\Types\TemplateTagType;
use Laravel\Surveyor\Types\Type;
use PHPUnit\Framework\TestCase;

final class SurveyorTypeFormatterTest extends TestCase
{
    public function test_intersections_are_sorted_deterministically(): void
    {
        $formatter = new SurveyorTypeFormatter();

        self::assertSame('int&string', $formatter->format(Type::intersection(Type::string(), Type::int())));

        self::assertSame('int&string', $formatter->format(Type::intersection(Type::int(), Type::string())));
    }

    public function test_template_tags_render_bounds_defaults_and_descriptions(): void
    {
        $formatter = new SurveyorTypeFormatter();
        $type = new TemplateTagType('TValue', Type::string(), Type::array([]), Type::int(), 'items');

        self::assertSame('TValue of string super int = array<mixed> - items', $formatter->format($type));
    }
}
