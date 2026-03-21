<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Tests\Unit\Support;

use Bnomei\ScipLaravel\Support\PolicyClassResolver;
use Bnomei\ScipLaravel\Support\ProjectPhpAnalysisCache;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function random_bytes;
use function sys_get_temp_dir;

final class PolicyClassResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        PolicyClassResolver::reset();
        ProjectPhpAnalysisCache::reset();
        parent::tearDown();
    }

    public function test_explicit_policy_map_is_reused_until_reset(): void
    {
        $root = $this->tempDirectory();

        try {
            $providerPath = $root . '/app/Providers/AuthServiceProvider.php';
            @mkdir(dirname($providerPath), 0777, true);

            file_put_contents($providerPath, $this->providerContents('UserPolicy'));

            $resolver = new PolicyClassResolver();

            self::assertSame('App\\Policies\\UserPolicy', $resolver->resolve($root, 'App\\Models\\User'));

            file_put_contents($providerPath, $this->providerContents('ChangedPolicy'));

            self::assertSame('App\\Policies\\UserPolicy', $resolver->resolve($root, 'App\\Models\\User'));

            PolicyClassResolver::reset();
            ProjectPhpAnalysisCache::reset();

            self::assertSame('App\\Policies\\ChangedPolicy', (new PolicyClassResolver())->resolve(
                $root,
                'App\\Models\\User',
            ));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function providerContents(string $policyClass): string
    {
        return <<<PHP
            <?php

            namespace App\\Providers;

            use App\\Models\\User;
            use App\\Policies\\{$policyClass};
            use Illuminate\\Support\\Facades\\Gate;

            final class AuthServiceProvider
            {
                public function boot(): void
                {
                    Gate::policy(User::class, {$policyClass}::class);
                }
            }
            PHP;
    }

    private function tempDirectory(): string
    {
        $root = sys_get_temp_dir() . '/policy-cache-' . bin2hex(random_bytes(8));
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
