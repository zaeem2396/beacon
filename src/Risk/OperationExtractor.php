<?php

declare(strict_types=1);

namespace Beacon\Risk;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\While_;

/**
 * Recognizes Laravel schema and query-builder calls in up and down.
 *
 * Comments, strings, and other methods are not treated as operations.
 * Nothing in the tree is executed.
 */
final class OperationExtractor
{
    private const OPERATION_LIMIT = 200;

    /**
     * @var list<string>
     */
    private const COLUMN_TYPES = [
        'id', 'increments', 'bigIncrements', 'integer', 'tinyInteger', 'smallInteger', 'mediumInteger',
        'bigInteger', 'unsignedInteger', 'unsignedBigInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
        'unsignedMediumInteger', 'foreignId', 'foreignIdFor', 'foreignUuid', 'foreignUlid', 'string', 'char',
        'text', 'mediumText', 'longText', 'tinyText', 'boolean', 'decimal', 'double', 'float', 'json', 'jsonb',
        'date', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz', 'timestamps', 'timestampsTz', 'softDeletes',
        'uuid', 'ulid', 'enum', 'set', 'binary', 'ipAddress', 'macAddress', 'year', 'time', 'rememberToken',
    ];

    /**
     * @param list<Node\Stmt> $statements
     */
    public function extract(array $statements): ExtractedMigration
    {
        $flags = new WalkState(MigrationPhase::Up);

        foreach ($statements as $statement) {
            $this->flags($statement, $flags);
        }

        $operations = [];
        $upLine = null;
        $downLine = null;
        $up = false;
        $down = false;

        foreach ($this->methods($statements) as $method) {
            $name = $method->name->toString();

            if ($name !== 'up' && $name !== 'down') {
                continue;
            }

            $phase = $name === 'up' ? MigrationPhase::Up : MigrationPhase::Down;
            $state = new WalkState($phase);

            if ($phase === MigrationPhase::Up) {
                $up = true;
                $upLine = $method->getStartLine();
            } else {
                $down = true;
                $downLine = $method->getStartLine();
            }

            foreach ($method->stmts ?? [] as $statement) {
                $this->walk($statement, $state);
            }

            foreach ($state->operations as $operation) {
                if (count($operations) >= self::OPERATION_LIMIT) {
                    break 2;
                }

                $operations[] = $operation;
            }
        }

        return new ExtractedMigration(
            $operations,
            true,
            $flags->sawInclude,
            $flags->sawEval,
            $flags->sawTrait,
            $upLine,
            $downLine,
            $up,
            $down,
        );
    }

    /**
     * @param list<Node\Stmt> $statements
     *
     * @return list<ClassMethod>
     */
    private function methods(array $statements): array
    {
        $methods = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                $nested = [];

                foreach ($statement->stmts as $nestedStatement) {
                    $nested[] = $nestedStatement;
                }

                $methods = array_merge($methods, $this->methods($nested));
            }

            if (! $statement instanceof Class_) {
                continue;
            }

            foreach ($statement->stmts as $stmt) {
                if ($stmt instanceof ClassMethod) {
                    $methods[] = $stmt;
                }
            }
        }

        return $methods;
    }

    private function flags(Node $node, WalkState $state): void
    {
        if ($node instanceof Include_) {
            $state->sawInclude = true;
        }

        if ($node instanceof Eval_) {
            $state->sawEval = true;
        }

        if ($node instanceof TraitUse) {
            $state->sawTrait = true;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name} ?? null;

            if ($value instanceof Node) {
                $this->flags($value, $state);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->flags($child, $state);
                    }
                }
            }
        }
    }

    private function walk(Node $node, WalkState $state): void
    {
        if ($this->isConditional($node)) {
            $previous = $state->conditional;
            $state->conditional = true;
            $this->children($node, $state);
            $state->conditional = $previous;

            return;
        }

        if ($node instanceof StaticCall) {
            $this->staticCall($node, $state);

            return;
        }

        if ($node instanceof MethodCall) {
            $this->methodCall($node, $state);

            return;
        }

        $this->children($node, $state);
    }

    private function children(Node $node, WalkState $state): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name} ?? null;

            if ($value instanceof Node) {
                $this->walk($value, $state);
            } elseif (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof Node) {
                        $this->walk($child, $state);
                    }
                }
            }
        }
    }

    private function isConditional(Node $node): bool
    {
        return $node instanceof If_
            || $node instanceof ElseIf_
            || $node instanceof While_
            || $node instanceof Do_
            || $node instanceof For_
            || $node instanceof Foreach_
            || $node instanceof Switch_
            || $node instanceof Catch_
            || $node instanceof Ternary;
    }

    private function staticCall(StaticCall $call, WalkState $state): void
    {
        $facade = $this->facade($call);
        $name = $this->callName($call->name);

        if ($facade === 'Schema' && $name !== null) {
            $this->schema($call, $name, $state);

            return;
        }

        if ($facade === 'DB' && $name !== null) {
            $this->database($call, $name, $state);

            return;
        }

        $this->children($call, $state);
    }

    private function schema(StaticCall $call, string $name, WalkState $state): void
    {
        $table = $this->stringArg($call, 0);
        $line = $call->getStartLine();
        $end = $call->getEndLine();

        if ($name === 'create') {
            $this->add($state, OperationKind::CreateTable, $line, $end, 'Schema::create(' . ($table ?? 'dynamic') . ')', $table, uncertain: $table === null);
            $this->walkSchemaBody($call, $state, true, $table);

            return;
        }

        if ($name === 'table') {
            $this->walkSchemaBody($call, $state, false, $table);

            return;
        }

        if ($name === 'drop' || $name === 'dropIfExists') {
            $this->add($state, OperationKind::DropTable, $line, $end, 'Schema::' . $name . '(' . ($table ?? 'dynamic') . ')', $table, uncertain: $table === null);
            $this->walkArgs($call, $state);

            return;
        }

        if ($name === 'rename') {
            $target = $this->stringArg($call, 1);
            $this->add(
                $state,
                OperationKind::RenameTable,
                $line,
                $end,
                'Schema::rename(' . ($table ?? 'dynamic') . ', ' . ($target ?? 'dynamic') . ')',
                $table,
                uncertain: $table === null || $target === null,
            );
            $this->walkArgs($call, $state);

            return;
        }

        $this->walkArgs($call, $state);
    }

    private function walkSchemaBody(StaticCall $call, WalkState $state, bool $onNewTable, ?string $table): void
    {
        $closure = $this->closureArg($call, 1);

        if ($closure !== null) {
            $this->walkClosure($closure, $state, $onNewTable, $table);
        }

        $this->walkArgs($call, $state, $closure);
    }

    private function database(StaticCall $call, string $name, WalkState $state): void
    {
        $line = $call->getStartLine();
        $end = $call->getEndLine();

        if (in_array($name, ['statement', 'unprepared', 'select', 'update', 'delete', 'insert'], true)) {
            $sql = $this->sqlArg($call, 0);

            if ($sql !== null) {
                $this->raw($state, $sql, $line, $end);
            } elseif ($this->hasArg($call, 0)) {
                $this->add($state, OperationKind::DynamicSql, $line, $end, 'DB::' . $name . '(dynamic)');
            }
        }

        if ($name === 'transaction' || $name === 'beginTransaction') {
            $this->add($state, OperationKind::Transaction, $line, $end, 'DB::' . $name . '()');
        }

        $this->walkArgs($call, $state);
    }

    /**
     * @param list<MethodCall> $chain
     */
    private function query(StaticCall $root, array $chain, WalkState $state): void
    {
        if ($this->callName($root->name) !== 'table' || $chain === []) {
            return;
        }

        $table = $this->stringArg($root, 0);
        $methods = [];

        foreach ($chain as $call) {
            $name = $this->callName($call->name);

            if ($name !== null) {
                $methods[] = $name;
            }
        }

        $last = $chain[count($chain) - 1];
        $chunked = in_array('chunk', $methods, true) || in_array('chunkById', $methods, true);
        $line = $root->getStartLine();
        $end = $last->getEndLine();
        $snippet = 'DB::table(' . ($table ?? 'dynamic') . ')->' . implode('->', $methods);

        if (in_array('truncate', $methods, true)) {
            $this->add($state, OperationKind::Truncate, $line, $end, $snippet, $table, uncertain: $table === null);

            return;
        }

        if (in_array('update', $methods, true) || in_array('insert', $methods, true) || in_array('upsert', $methods, true)) {
            $this->add($state, OperationKind::UpdateRows, $line, $end, $snippet, $table, chunked: $chunked, uncertain: $table === null);

            return;
        }

        if (in_array('delete', $methods, true)) {
            $this->add($state, OperationKind::DeleteRows, $line, $end, $snippet, $table, chunked: $chunked, uncertain: $table === null);

            return;
        }

        if (array_intersect($methods, ['get', 'cursor', 'lazy', 'each', 'pluck', 'chunk', 'chunkById']) !== []) {
            $this->add(
                $state,
                OperationKind::ReadRows,
                $line,
                $end,
                $snippet,
                $table,
                chunked: $chunked,
                rowByRow: ! $chunked,
                uncertain: $table === null,
            );
        }
    }

    /**
     * @param list<MethodCall> $chain
     */
    private function blueprint(array $chain, WalkState $state): void
    {
        $first = $chain[0];
        $name = $this->callName($first->name);

        if ($name === null) {
            return;
        }

        $last = $chain[count($chain) - 1];
        $line = $first->getStartLine();
        $end = $last->getEndLine();
        $modifiers = $this->modifiers(array_slice($chain, 1));
        $column = $this->stringArg($first, 0);
        $snippet = 'table->' . $name . '(' . ($column ?? '') . ')';
        $shared = [
            'column' => $column,
            'nullable' => $modifiers['nullable'],
            'hasDefault' => $modifiers['hasDefault'],
            'change' => $modifiers['change'],
            'unique' => $modifiers['unique'],
            'index' => $modifiers['index'],
            'foreign' => $modifiers['foreign'],
            'cascadeDelete' => $modifiers['cascadeDelete'],
            'cascadeUpdate' => $modifiers['cascadeUpdate'],
        ];

        if ($name === 'dropColumn' || $name === 'dropColumns') {
            $columns = $this->strings($first);

            if ($columns === []) {
                $this->add($state, OperationKind::DropColumn, $line, $end, 'table->dropColumn(dynamic)', $state->table, uncertain: true);

                return;
            }

            foreach ($columns as $dropped) {
                $this->add($state, OperationKind::DropColumn, $line, $end, 'table->dropColumn(' . $dropped . ')', $state->table, column: $dropped);
            }

            return;
        }

        if ($name === 'renameColumn') {
            $target = $this->stringArg($first, 1);
            $this->add(
                $state,
                OperationKind::RenameColumn,
                $line,
                $end,
                'table->renameColumn(' . ($column ?? 'dynamic') . ', ' . ($target ?? 'dynamic') . ')',
                $state->table,
                column: $column,
                uncertain: $column === null || $target === null,
            );

            return;
        }

        if (in_array($name, ['dropIndex', 'dropUnique', 'dropPrimary'], true)) {
            $this->add($state, OperationKind::DropIndex, $line, $end, 'table->' . $name . '(' . ($column ?? '') . ')', $state->table, column: $column);

            return;
        }

        if (in_array($name, ['dropForeign', 'dropConstrainedForeignId'], true)) {
            $this->add($state, OperationKind::DropForeign, $line, $end, 'table->' . $name . '(' . ($column ?? '') . ')', $state->table, column: $column);

            return;
        }

        if (in_array($name, ['index', 'fullText', 'spatialIndex', 'primary'], true)) {
            $this->add($state, OperationKind::AddIndex, $line, $end, $snippet, $state->table, column: $column, index: true);

            return;
        }

        if ($name === 'unique') {
            $this->add($state, OperationKind::AddUnique, $line, $end, $snippet, $state->table, column: $column, unique: true);

            return;
        }

        if ($name === 'foreign') {
            $shared['foreign'] = true;
            $this->add($state, OperationKind::AddForeign, $line, $end, $snippet, $state->table, ...$shared);

            return;
        }

        if (! in_array($name, self::COLUMN_TYPES, true)) {
            return;
        }

        $foreign = $shared['foreign'] || in_array($name, ['foreignId', 'foreignIdFor', 'foreignUuid', 'foreignUlid'], true);
        $benign = in_array($name, ['id', 'increments', 'bigIncrements', 'timestamps', 'timestampsTz', 'softDeletes', 'rememberToken'], true);

        if ($benign && ! $modifiers['change'] && ! $modifiers['unique'] && ! $modifiers['index'] && ! $foreign && ! $modifiers['cascadeDelete']) {
            return;
        }

        $kind = $modifiers['change'] ? OperationKind::ChangeColumn : OperationKind::AddColumn;
        $length = in_array($name, ['string', 'char'], true) ? $this->integerArg($first, 1) : null;
        $precision = in_array($name, ['decimal', 'float', 'double'], true) ? $this->integerArg($first, 1) : null;
        $scale = $precision === null ? null : $this->integerArg($first, 2);
        $this->add(
            $state,
            $kind,
            $line,
            $end,
            $snippet,
            $state->table,
            column: $column,
            columnType: $name,
            length: $length,
            precision: $precision,
            scale: $scale,
            nullable: $shared['nullable'],
            hasDefault: $shared['hasDefault'],
            change: $shared['change'],
            unique: $shared['unique'],
            index: $shared['index'],
            foreign: $foreign,
            cascadeDelete: $shared['cascadeDelete'],
            cascadeUpdate: $shared['cascadeUpdate'],
        );
    }

    /**
     * @param list<MethodCall> $calls
     *
     * @return array{nullable: bool, hasDefault: bool, change: bool, unique: bool, index: bool, foreign: bool, cascadeDelete: bool, cascadeUpdate: bool}
     */
    private function modifiers(array $calls): array
    {
        $nullable = false;
        $hasDefault = false;
        $change = false;
        $unique = false;
        $index = false;
        $foreign = false;
        $cascadeDelete = false;
        $cascadeUpdate = false;

        foreach ($calls as $call) {
            $name = $this->callName($call->name);

            if ($name === 'nullable') {
                $arg = $call->args[0] ?? null;
                $nullable = ! $arg instanceof Arg || $this->literalBool($arg->value) !== false;
            }

            if ($name === 'default') {
                $hasDefault = true;
            }

            if ($name === 'change') {
                $change = true;
            }

            if ($name === 'unique') {
                $unique = true;
            }

            if ($name === 'index' || $name === 'primary') {
                $index = true;
            }

            if (in_array($name, ['constrained', 'references', 'foreign'], true)) {
                $foreign = true;
            }

            if ($name === 'cascadeOnDelete') {
                $cascadeDelete = true;
                $foreign = true;
            }

            if ($name === 'cascadeOnUpdate') {
                $cascadeUpdate = true;
                $foreign = true;
            }
        }

        return [
            'nullable' => $nullable,
            'hasDefault' => $hasDefault,
            'change' => $change,
            'unique' => $unique,
            'index' => $index,
            'foreign' => $foreign,
            'cascadeDelete' => $cascadeDelete,
            'cascadeUpdate' => $cascadeUpdate,
        ];
    }

    private function methodCall(MethodCall $outer, WalkState $state): void
    {
        $root = $outer->var;
        $chain = [$outer];

        while ($root instanceof MethodCall) {
            $chain[] = $root;
            $root = $root->var;
        }

        $chain = array_reverse($chain);

        if ($root instanceof Variable && is_string($root->name) && $root->name === $state->blueprint) {
            $this->blueprint($chain, $state);
        } elseif ($root instanceof StaticCall) {
            $this->query($root, $chain, $state);
        }

        foreach ($chain as $call) {
            $chunk = in_array($this->callName($call->name), ['chunk', 'chunkById'], true);

            foreach ($call->args as $arg) {
                if (! $arg instanceof Arg) {
                    continue;
                }

                if ($chunk && $arg->value instanceof Closure) {
                    $previous = $state->inChunk;
                    $state->inChunk = true;
                    $this->walk($arg->value, $state);
                    $state->inChunk = $previous;

                    continue;
                }

                $this->walk($arg->value, $state);
            }
        }

        if (! $root instanceof StaticCall) {
            $this->walk($root, $state);
        }
    }

    private function walkClosure(Closure $closure, WalkState $state, bool $onNewTable, ?string $table): void
    {
        $blueprint = $state->blueprint;
        $created = $state->onNewTable;
        $current = $state->table;
        $state->blueprint = $this->parameterName($closure);
        $state->onNewTable = $onNewTable;
        $state->table = $table;

        foreach ($closure->stmts as $statement) {
            $this->walk($statement, $state);
        }

        $state->blueprint = $blueprint;
        $state->onNewTable = $created;
        $state->table = $current;
    }

    private function raw(WalkState $state, string $sql, int $line, int $end): void
    {
        $effect = SqlClassifier::effect($sql);
        $kind = match ($effect) {
            'truncate' => OperationKind::Truncate,
            'update_rows' => OperationKind::UpdateRows,
            'select_rows' => OperationKind::ReadRows,
            'create_index' => OperationKind::AddIndex,
            'add_unique' => OperationKind::AddUnique,
            'drop_index' => OperationKind::DropIndex,
            'add_foreign' => OperationKind::AddForeign,
            'drop_foreign' => OperationKind::DropForeign,
            'alter_table' => OperationKind::ChangeColumn,
            default => OperationKind::RawSql,
        };

        $this->add(
            $state,
            $kind,
            $line,
            $end,
            'DB::statement(' . $sql . ')',
            SqlClassifier::table($sql),
            sqlEffect: $effect,
            uncertain: $effect === 'alter_table',
            change: $effect === 'alter_table',
        );
    }

    private function add(
        WalkState $state,
        OperationKind $kind,
        int $line,
        int $end,
        string $snippet,
        ?string $table = null,
        ?string $column = null,
        ?string $columnType = null,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        bool $nullable = false,
        bool $hasDefault = false,
        bool $change = false,
        bool $unique = false,
        bool $index = false,
        bool $foreign = false,
        bool $cascadeDelete = false,
        bool $cascadeUpdate = false,
        bool $chunked = false,
        bool $rowByRow = false,
        ?string $sqlEffect = null,
        bool $uncertain = false,
    ): void {
        if (count($state->operations) >= self::OPERATION_LIMIT || $line < 1) {
            return;
        }

        $state->operations[] = new Operation(
            $kind,
            $state->phase,
            $line,
            max($end, $line),
            RiskText::snippet($snippet),
            $table,
            $column,
            $columnType,
            $length,
            $precision,
            $scale,
            $nullable,
            $hasDefault,
            $change,
            $unique,
            $index,
            $foreign,
            $cascadeDelete,
            $cascadeUpdate,
            $state->conditional || $uncertain,
            $state->onNewTable,
            $chunked || $state->inChunk,
            $rowByRow,
            $sqlEffect,
        );
    }

    private function walkArgs(StaticCall $call, WalkState $state, ?Closure $skip = null): void
    {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Arg || $arg->value === $skip) {
                continue;
            }

            $this->walk($arg->value, $state);
        }
    }

    private function facade(StaticCall $call): ?string
    {
        if (! $call->class instanceof Name) {
            return null;
        }

        $last = $call->class->getLast();

        return in_array($last, ['Schema', 'DB'], true) ? $last : null;
    }

    private function callName(Node $node): ?string
    {
        return $node instanceof Identifier ? $node->toString() : null;
    }

    private function stringArg(StaticCall|MethodCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;

        if (! $arg instanceof Arg || ! $arg->value instanceof String_) {
            return null;
        }

        $value = $arg->value->value;

        if (strlen($value) > 64 || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function sqlArg(StaticCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;

        if (! $arg instanceof Arg || ! $arg->value instanceof String_) {
            return null;
        }

        return $arg->value->value;
    }

    private function hasArg(StaticCall $call, int $index): bool
    {
        return isset($call->args[$index]);
    }

    private function integerArg(MethodCall $call, int $index): ?int
    {
        $arg = $call->args[$index] ?? null;

        if (! $arg instanceof Arg || ! $arg->value instanceof Int_) {
            return null;
        }

        $value = $arg->value->value;

        return $value >= 0 && $value <= 16777216 ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function strings(MethodCall $call): array
    {
        $arg = $call->args[0] ?? null;

        if (! $arg instanceof Arg) {
            return [];
        }

        if ($arg->value instanceof String_) {
            $value = $arg->value->value;

            return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? [$value] : [];
        }

        if (! $arg->value instanceof Array_) {
            return [];
        }

        $values = [];

        foreach ($arg->value->items as $item) {
            if (! $item instanceof ArrayItem || ! $item->value instanceof String_) {
                continue;
            }

            $value = $item->value->value;

            if (preg_match('/^[A-Za-z0-9_]+$/', $value) === 1) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function closureArg(StaticCall $call, int $index): ?Closure
    {
        $arg = $call->args[$index] ?? null;

        if (! $arg instanceof Arg || ! $arg->value instanceof Closure) {
            return null;
        }

        return $arg->value;
    }

    private function parameterName(Closure $closure): ?string
    {
        $parameter = $closure->params[0] ?? null;

        if ($parameter === null || ! $parameter->var instanceof Variable || ! is_string($parameter->var->name)) {
            return null;
        }

        return $parameter->var->name;
    }

    private function literalBool(Node $node): ?bool
    {
        if (! $node instanceof ConstFetch) {
            return null;
        }

        return match (strtolower($node->name->getLast())) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
