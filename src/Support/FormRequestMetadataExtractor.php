<?php

declare(strict_types=1);

namespace Bnomei\ScipLaravel\Support;

use Illuminate\Foundation\Http\FormRequest;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

use function array_values;
use function is_string;
use function ltrim;
use function strtolower;

final class FormRequestMetadataExtractor
{
    private readonly ProjectPhpAnalysisCache $analysisCache;

    private NodeFinder $nodeFinder;

    public function __construct(
        ?ProjectPhpAnalysisCache $analysisCache = null,
        private readonly ValidationRuleFormatter $ruleFormatter = new ValidationRuleFormatter(),
    ) {
        $this->analysisCache = $analysisCache ?? ProjectPhpAnalysisCache::shared();
        $this->nodeFinder = new NodeFinder();
    }

    public function extract(string $filePath): ?FormRequestMetadata
    {
        $cacheKey = $filePath;

        return $this->analysisCache->remember('form-request-metadata', $cacheKey, function () use (
            $filePath,
        ): ?FormRequestMetadata {
            $contents = $this->analysisCache->contents($filePath);

            if (!is_string($contents) || $contents === '') {
                return null;
            }

            $ast = $this->analysisCache->resolvedAst($filePath);

            if ($ast === null) {
                return null;
            }

            $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);

            if (!$class instanceof Class_) {
                return null;
            }

            $className = $this->resolvedClassName($class);

            if ($className === null || !is_subclass_of($className, FormRequest::class)) {
                return null;
            }

            $classDocumentation = [];
            $rulesMethodDocumentation = [];
            $rulesMethodLine = null;

            foreach ($class->getMethods() as $method) {
                $methodName = strtolower($method->name->toString());
                $array = $this->returnedArray($method);

                if (!$array instanceof Array_) {
                    continue;
                }

                if ($methodName === 'rules') {
                    $formatted = $this->ruleFormatter->formatLiteralRuleMap($array);

                    if ($formatted !== '') {
                        $classDocumentation[] = 'Laravel Form Request rules: ' . $formatted;
                        $rulesMethodDocumentation[] = 'Laravel Form Request rules: ' . $formatted;
                        $rulesMethodLine = $method->getStartLine();
                    }

                    continue;
                }

                if ($methodName === 'messages') {
                    $formatted = $this->formatStringMap($array);

                    if ($formatted !== '') {
                        $classDocumentation[] = 'Validation messages: ' . $formatted;
                    }

                    continue;
                }

                if ($methodName === 'attributes' || $methodName === 'validationattributes') {
                    $formatted = $this->formatStringMap($array);

                    if ($formatted !== '') {
                        $classDocumentation[] = 'Validation attributes: ' . $formatted;
                    }
                }
            }

            if ($classDocumentation === [] && $rulesMethodDocumentation === []) {
                return null;
            }

            return new FormRequestMetadata(
                className: $className,
                classLine: $class->getStartLine(),
                classDocumentation: array_values(array_unique($classDocumentation)),
                rulesMethodLine: $rulesMethodLine,
                rulesMethodDocumentation: array_values(array_unique($rulesMethodDocumentation)),
            );
        });
    }

    private function returnedArray(ClassMethod $method): ?Array_
    {
        if (!is_array($method->stmts)) {
            return null;
        }

        $return = $this->nodeFinder->findFirstInstanceOf($method->stmts, Return_::class);

        return $return instanceof Return_ && $return->expr instanceof Array_ ? $return->expr : null;
    }

    private function resolvedClassName(Class_ $class): ?string
    {
        $namespacedName = $class->namespacedName ?? null;

        return $namespacedName instanceof Name ? ltrim($namespacedName->toString(), '\\') : null;
    }

    private function formatStringMap(Array_ $array): string
    {
        $pairs = [];

        foreach ($array->items as $item) {
            if (!$item?->key instanceof String_ || !$item->value instanceof String_) {
                continue;
            }

            $pairs[] = $item->key->value . ' => ' . $item->value->value;
        }

        return implode('; ', $pairs);
    }
}
