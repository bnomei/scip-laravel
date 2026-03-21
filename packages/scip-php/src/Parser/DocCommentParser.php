<?php

declare(strict_types=1);

namespace ScipPhp\Parser;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

use function count;

final class DocCommentParser
{
    /** @var array<string, PhpDocNode> */
    private static array $cache = [];

    private PhpDocParser $parser;

    private Lexer $lexer;

    public function __construct()
    {
        $usedAttributes = ['lines' => true, 'indexes' => true];
        $config = new ParserConfig($usedAttributes);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new PhpDocParser($config, $typeParser, $constExprParser);
        $this->lexer = new Lexer($config);
    }

    public function parsePropertyType(Node $node): ?TypeNode
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return null;
        }
        $n = $this->parse($doc);
        $tags = $n->getVarTagValues();
        if (count($tags) === 0) {
            return null;
        }
        return $tags[0]->type;
    }

    public function parseReturnType(Node $node): ?TypeNode
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return null;
        }
        $n = $this->parse($doc);
        $tags = $n->getReturnTagValues();
        if (count($tags) === 0) {
            return null;
        }
        return $tags[0]->type;
    }

    /** @return array<string, PropertyTagValueNode> */
    public function parseProperties(Node $node): array
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }
        $n = $this->parse($doc);
        return [
            ...$n->getPropertyTagValues(),
            ...$n->getPropertyReadTagValues(),
            ...$n->getPropertyWriteTagValues(),
        ];
    }

    /** @return array<string, MethodTagValueNode> */
    public function parseMethods(Node $node): array
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return [];
        }
        $n = $this->parse($doc);
        return $n->getMethodTagValues();
    }

    private function parse(Doc $doc): PhpDocNode
    {
        $comment = $doc->getText();

        if (isset(self::$cache[$comment])) {
            return self::$cache[$comment];
        }

        $tokens = $this->lexer->tokenize($comment);
        $iterator = new TokenIterator($tokens);

        return self::$cache[$comment] = $this->parser->parse($iterator);
    }
}
