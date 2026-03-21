<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use Bnomei\ScipLaravel\Support\VoltBladeModelMemberReferenceFinder;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function realpath;
use function sort;
use function sys_get_temp_dir;

final class VoltBladeModelMemberReferenceFinderTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_finds_scoped_blade_model_method_references(): void
    {
        $root = $this->tempDirectory();

        try {
            @mkdir($root . '/resources/views/acceptance', 0777, true);
            $filePath = $root . '/resources/views/acceptance/course-probe.blade.php';
            file_put_contents($filePath, <<<'BLADE'
                <div>
                    {{ $course->submissionsOpen() ? 'Open' : 'Closed' }}
                </div>
                BLADE);

            $references = (new VoltBladeModelMemberReferenceFinder())->find(
                $root,
                ['App\\Models\\Course' => true],
                [realpath($filePath) ?: $filePath => ['course' => 'App\\Models\\Course']],
            );

            $summary = array_map(
                static fn($reference): string => (
                    basename($reference->filePath)
                    . ':'
                    . $reference->memberName
                    . ':'
                    . ($reference->methodCall ? 'method' : 'property')
                ),
                $references,
            );
            sort($summary);

            self::assertSame(
                [
                    'course-probe.blade.php:submissionsOpen:method',
                ],
                $summary,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/volt-blade-model-member-reference-finder-' . bin2hex(random_bytes(8));
        @mkdir($root, 0777, true);

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
