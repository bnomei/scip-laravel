<?php

declare(strict_types=1);

namespace ScipPhp\Parser;

use Closure;
use Override;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser as PhpParser;
use PhpParser\ParserFactory;
use RuntimeException;
use ScipPhp\File\Reader;

final class Parser
{
    private ParentConnectingVisitor $parentConnectingVisitor;

    private NameResolver $nameResolver;

    private PhpParser $parser;

    /**
     * @var array<string, array{code: non-empty-string, pos: PosResolver, stmts: list<Node>}>
     */
    private array $cache = [];

    public function __construct()
    {
        $this->parentConnectingVisitor = new ParentConnectingVisitor();
        $this->nameResolver = new NameResolver();
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param  non-empty-string  $filename
     * @param  Closure(PosResolver, Node): void  $visitor
     */
    public function traverse(string $filename, Closure $visitor): void
    {
        ['pos' => $pos, 'stmts' => $stmts] = $this->parsed($filename);

        $t = new NodeTraverser(
            new class ($pos, $visitor) extends NodeVisitorAbstract
            {
                public function __construct(
                    private readonly PosResolver $pos,
                    private readonly Closure $visitor,
                ) {
                }

                #[Override]
                public function leaveNode(Node $n): ?Node
                {
                    ($this->visitor)($this->pos, $n);
                    return null;
                }
            },
        );

        $t->traverse($stmts);
    }

    /**
     * @param non-empty-string $filename
     * @return array{code: non-empty-string, pos: PosResolver, stmts: list<Node>}
     */
    private function parsed(string $filename): array
    {
        $code = Reader::read($filename);

        if ($code === '') {
            throw new RuntimeException("Cannot parse file: {$filename}.");
        }

        $cached = $this->cache[$filename] ?? null;

        if ($cached !== null && $cached['code'] === $code) {
            return $cached;
        }

        $stmts = $this->parser->parse($code);

        if ($stmts === null) {
            throw new RuntimeException("Cannot parse file: {$filename}.");
        }

        $resolver = new NodeTraverser(
            $this->nameResolver,
            $this->parentConnectingVisitor,
        );

        return $this->cache[$filename] = [
            'code' => $code,
            'pos' => new PosResolver($code),
            'stmts' => $resolver->traverse($stmts),
        ];
    }
}
