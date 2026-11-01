<?php

declare(strict_types=1);

namespace Beacon\Risk;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Parses migration PHP with nikic/php-parser.
 *
 * The parser is a production dependency because chained schema calls,
 * closures, comments, and strings cannot be separated reliably with regular
 * expressions alone. PHP-Parser 5 supports the same PHP 8.2 baseline as
 * Beacon. Parsing does not evaluate the file. If the parser class is absent,
 * the result says the parser is unavailable and the file is not treated as safe.
 */
final class PhpParserSyntax
{
    /**
     * @return array{available: bool, parsed: bool, statements: list<Node\Stmt>}
     */
    public function parse(string $source): array
    {
        if (! class_exists(ParserFactory::class)) {
            return ['available' => false, 'parsed' => false, 'statements' => []];
        }

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($source);
        } catch (Error) {
            return ['available' => true, 'parsed' => false, 'statements' => []];
        }

        if ($statements === null) {
            return ['available' => true, 'parsed' => false, 'statements' => []];
        }

        $list = [];

        foreach ($statements as $statement) {
            $list[] = $statement;
        }

        return ['available' => true, 'parsed' => true, 'statements' => $list];
    }
}
