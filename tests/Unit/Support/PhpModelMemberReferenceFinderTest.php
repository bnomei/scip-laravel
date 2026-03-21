<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\PhpModelMemberReference;
use Bnomei\ScipLaravel\Support\PhpModelMemberReferenceFinder;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sort;
use function sys_get_temp_dir;

final class PhpModelMemberReferenceFinderTest extends TestCase
{
    protected function tearDown(): void
    {
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_finds_precise_declared_model_member_references(): void
    {
        $root = $this->tempDirectory();

        try {
            @mkdir($root . '/app/Models', 0777, true);
            @mkdir($root . '/app/Support', 0777, true);

            file_put_contents($root . '/app/Models/Course.php', <<<'PHP'
                <?php

                namespace App\Models;

                final class Course
                {
                    public const DEFAULT_LABEL = 'course';

                    public string $nickname = 'course';

                    public function submissionsOpen(): bool
                    {
                        return true;
                    }

                    public function declaredSummary(): string
                    {
                        return $this->nickname;
                    }

                    public static function declaredSlug(): string
                    {
                        return self::DEFAULT_LABEL;
                    }

                    public function internalReads(): array
                    {
                        return [
                            $this->nickname,
                            $this->declaredSummary(),
                            self::declaredSlug(),
                            static::declaredSlug(),
                            self::DEFAULT_LABEL,
                            static::DEFAULT_LABEL,
                        ];
                    }
                }
                PHP);

            file_put_contents($root . '/app/Support/Probe.php', <<<'PHP'
                <?php

                namespace App\Support;

                use App\Models\Course;

                final class Probe
                {
                    public function inspect(Course $course): array
                    {
                        $nickname = $course->nickname;
                            $course->nickname = 'updated';

                            return [
                                $nickname,
                                $course->nickname,
                                $course->submissionsOpen(),
                                $course->declaredSummary(),
                                Course::declaredSlug(),
                                Course::DEFAULT_LABEL,
                        ];
                    }
                }
                PHP);

            $references = (new PhpModelMemberReferenceFinder())->find($root, [
                'App\\Models\\Course' => true,
            ]);

            $summary = array_map(static function (PhpModelMemberReference $reference): string {
                $kind = $reference->constantFetch ? 'const' : ($reference->methodCall ? 'method' : 'property');
                $role = $reference->write ? 'write' : 'read';

                return basename($reference->filePath) . ':' . $reference->memberName . ':' . $kind . ':' . $role;
            }, $references);
            sort($summary);

            self::assertContains('Course.php:nickname:property:read', $summary);
            self::assertContains('Course.php:declaredSummary:method:read', $summary);
            self::assertContains('Course.php:declaredSlug:method:read', $summary);
            self::assertContains('Probe.php:nickname:property:read', $summary);
            self::assertContains('Probe.php:nickname:property:write', $summary);
            self::assertContains('Probe.php:submissionsOpen:method:read', $summary);
            self::assertContains('Probe.php:declaredSummary:method:read', $summary);
            self::assertContains('Probe.php:declaredSlug:method:read', $summary);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/php-model-member-reference-finder-' . bin2hex(random_bytes(8));
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
