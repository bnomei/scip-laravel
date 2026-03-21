<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function spl_object_id;
use function sys_get_temp_dir;

final class ProjectPhpAnalysisCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_project_php_files_and_resolved_ast_are_reused_until_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $firstFile = $root . '/app/Example.php';
            $secondFile = $root . '/app/Second.php';
            @mkdir($root . '/app', 0777, true);

            file_put_contents($firstFile, <<<'PHP'
                <?php

                namespace App;

                final class Example
                {
                    public function one(): int
                    {
                        return 1;
                    }
                }
                PHP);

            $cache = ProjectPhpAnalysisCache::shared();
            $firstScan = $cache->projectPhpFiles($root);
            $firstAst = $cache->resolvedAst($firstFile);

            self::assertCount(1, $firstScan);
            self::assertIsArray($firstAst);

            file_put_contents($secondFile, "<?php\n\nnamespace App;\n\nfinal class Second {}\n");
            file_put_contents($firstFile, <<<'PHP'
                <?php

                namespace App;

                final class Example
                {
                    public function two(): int
                    {
                        return 2;
                    }
                }
                PHP);

            $secondScan = $cache->projectPhpFiles($root);
            $secondAst = $cache->resolvedAst($firstFile);

            self::assertSame($firstScan, $secondScan);
            self::assertIsArray($secondAst);
            self::assertSame(spl_object_id($firstAst[0]), spl_object_id($secondAst[0]));

            ProjectPhpAnalysisCache::reset();

            $refreshedCache = ProjectPhpAnalysisCache::shared();
            $refreshedScan = $refreshedCache->projectPhpFiles($root);
            $refreshedAst = $refreshedCache->resolvedAst($firstFile);

            self::assertCount(2, $refreshedScan);
            self::assertContains($secondFile, $refreshedScan);
            self::assertIsArray($refreshedAst);
            self::assertNotSame(spl_object_id($firstAst[0]), spl_object_id($refreshedAst[0]));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/php-analysis-cache-' . bin2hex(random_bytes(8));
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
