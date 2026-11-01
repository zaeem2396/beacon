<?php

declare(strict_types=1);

namespace Beacon\Risk\Rules;

use Beacon\Risk\FindingFactory;

/**
 * The built-in rule set, in stable rule-id order.
 */
final class RuleCatalog
{
    /**
     * @var list<RiskRule>
     */
    private array $rules;

    public function __construct(FindingFactory $findings)
    {
        $this->rules = [
            new DropTableRule($findings),
            new DropColumnRule($findings),
            new TruncateTableRule($findings),
            new NonNullableColumnRule($findings),
            new UniqueConstraintRule($findings),
            new ForeignKeyRule($findings),
            new ChangeColumnRule($findings),
            new RenameColumnRule($findings),
            new RenameTableRule($findings),
            new AddIndexRule($findings),
            new DropIndexRule($findings),
            new NarrowColumnRule($findings),
            new BackfillRule($findings),
            new CascadeDeleteRule($findings),
            new DestructiveSqlRule($findings),
            new DynamicSqlRule($findings),
            new TransactionRule($findings),
            new RollbackRule($findings),
            new MixedChangeRule($findings),
            new NullabilityRule($findings),
            new UnrecognizedSqlRule($findings),
            new DropForeignKeyRule($findings),
        ];
    }

    /**
     * @return list<RiskRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
