<?php

declare(strict_types=1);

namespace Beacon\Comparison;

use Beacon\Data\SchemaDifference;
use Beacon\Schema\ObjectType;

/**
 * Compares two normalized snapshots.
 *
 * Metadata such as timestamps, identifiers, and database names is not a
 * structural change. Missing metadata is reported as incomplete instead of
 * being treated as a value change. Rename pairing runs only when requested
 * and is marked heuristic.
 */
final class SchemaComparator
{
    public const DIFFERENCE_LIMIT = 1000;

    /** @var list<SchemaDifference> */
    private array $differences = [];

    private bool $capped = false;

    public function compare(SnapshotDocument $baseline, SnapshotDocument $candidate, bool $detectRenames): ComparisonDraft
    {
        $this->differences = [];
        $this->capped = false;
        $limitations = ['View definitions were not compared.'];
        $complete = $baseline->metadata->complete && $candidate->metadata->complete;
        $presence = $complete;

        if (! $complete) {
            $limitations[] = 'A snapshot is incomplete, so added and removed objects were not classified.';
        }

        if ($baseline->metadata->driver !== null && $candidate->metadata->driver !== null && $baseline->metadata->driver !== $candidate->metadata->driver) {
            $this->add(
                DifferenceCategory::Metadata,
                'drivers',
                null,
                ChangeType::Modified,
                'driver',
                $baseline->metadata->driver,
                $candidate->metadata->driver,
                Severity::Informational,
                Confidence::High,
                'The snapshots name different database drivers. Structural fields were still compared.',
            );
        }

        $columns = $baseline->metadata->columnsIncluded && $candidate->metadata->columnsIncluded;
        $indexes = $baseline->metadata->indexesIncluded && $candidate->metadata->indexesIncluded;
        $references = $baseline->metadata->referencesIncluded && $candidate->metadata->referencesIncluded;
        $views = $baseline->metadata->viewsIncluded && $candidate->metadata->viewsIncluded;

        if (! $columns) {
            $limitations[] = 'Column metadata is incomplete, so columns were not compared.';
        }

        if (! $indexes) {
            $limitations[] = 'Index metadata is incomplete, so indexes and primary keys were not compared.';
        }

        if (! $references) {
            $limitations[] = 'Foreign key metadata is incomplete, so foreign keys were not compared.';
        }

        if (! $views) {
            $limitations[] = 'View metadata is incomplete, so views were not compared.';
        }

        $this->objects(
            $this->indexed($baseline->objects, ObjectType::Table),
            $this->indexed($candidate->objects, ObjectType::Table),
            DifferenceCategory::Table,
            $presence,
            $detectRenames,
            $columns,
            $indexes,
            $references,
        );

        if ($views) {
            $this->objects(
                $this->indexed($baseline->objects, ObjectType::View),
                $this->indexed($candidate->objects, ObjectType::View),
                DifferenceCategory::View,
                $presence,
                false,
                $columns,
                false,
                false,
            );
        }

        if ($this->capped) {
            $limitations[] = 'Difference output stopped at the difference limit.';
            $complete = false;
        }

        usort(
            $this->differences,
            static fn (SchemaDifference $left, SchemaDifference $right): int => [$right->severity->rank(), $left->id] <=> [$left->severity->rank(), $right->id],
        );

        return new ComparisonDraft($this->differences, $complete && $limitations === ['View definitions were not compared.'], array_slice($limitations, 0, 8));
    }

    /**
     * @param list<SnapshotObject> $objects
     *
     * @return array<string, SnapshotObject>
     */
    private function indexed(array $objects, ObjectType $type): array
    {
        $indexed = [];

        foreach ($objects as $object) {
            if ($object->type === $type) {
                $indexed[$object->name] = $object;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, SnapshotObject> $baseline
     * @param array<string, SnapshotObject> $candidate
     */
    private function objects(
        array $baseline,
        array $candidate,
        DifferenceCategory $category,
        bool $presence,
        bool $detectRenames,
        bool $columns,
        bool $indexes,
        bool $references,
    ): void {
        $removed = array_diff_key($baseline, $candidate);
        $added = array_diff_key($candidate, $baseline);

        if ($presence && $detectRenames && $columns) {
            [$removed, $added] = $this->renameObjects($removed, $added, $category);
        }

        if ($presence) {
            foreach ($removed as $object) {
                $this->presence($category, $object->name, ChangeType::Removed);
            }

            foreach ($added as $object) {
                $this->presence($category, $object->name, ChangeType::Added);
            }
        }

        foreach (array_intersect_key($baseline, $candidate) as $name => $before) {
            $after = $candidate[$name];
            $this->columns($before, $after, $category === DifferenceCategory::View ? DifferenceCategory::View : DifferenceCategory::Column, $columns, $detectRenames);

            if ($indexes) {
                $this->primaryKey($before, $after);
                $this->indexes($before, $after);
            }

            if ($references) {
                $this->references($before, $after);
            }
        }
    }

    /**
     * @param array<string, SnapshotObject> $removed
     * @param array<string, SnapshotObject> $added
     *
     * @return array{0: array<string, SnapshotObject>, 1: array<string, SnapshotObject>}
     */
    private function renameObjects(array $removed, array $added, DifferenceCategory $category): array
    {
        $removedBySignature = $this->groupObjects($removed);
        $addedBySignature = $this->groupObjects($added);

        foreach ($removed as $name => $object) {
            $signature = $object->structureSignature();

            if ($signature === '' || count($removedBySignature[$signature] ?? []) !== 1 || count($addedBySignature[$signature] ?? []) !== 1) {
                continue;
            }

            $match = $addedBySignature[$signature][0];
            $this->add(
                $category,
                $object->name,
                $object->name,
                ChangeType::Renamed,
                'name',
                $object->name,
                $match->name,
                Severity::Medium,
                Confidence::Medium,
                'This match is a heuristic rename, not a confirmed rename.',
                true,
                true,
            );
            unset($removed[$name], $added[$match->name]);
        }

        return [$removed, $added];
    }

    /**
     * @param array<string, SnapshotObject> $objects
     *
     * @return array<string, list<SnapshotObject>>
     */
    private function groupObjects(array $objects): array
    {
        $grouped = [];

        foreach ($objects as $object) {
            $grouped[$object->structureSignature()][] = $object;
        }

        return $grouped;
    }

    private function presence(DifferenceCategory $category, string $name, ChangeType $change): void
    {
        $added = $change === ChangeType::Added;
        $view = $category === DifferenceCategory::View;
        $severity = match (true) {
            ! $added && ! $view => Severity::Critical,
            ! $added && $view => Severity::Medium,
            $added => Severity::Informational,
        };
        $explanation = match (true) {
            $added && $view => 'The view is present in the candidate snapshot and absent from the baseline.',
            $added => 'The table is present in the candidate snapshot and absent from the baseline.',
            $view => 'The view is present in the baseline snapshot and absent from the candidate.',
            default => 'The table is present in the baseline snapshot and absent from the candidate. Stored rows were not read.',
        };

        $this->add($category, $name, $view ? null : $name, $change, null, null, null, $severity, Confidence::High, $explanation);
    }

    private function columns(SnapshotObject $before, SnapshotObject $after, DifferenceCategory $category, bool $enabled, bool $detectRenames): void
    {
        if (! $enabled) {
            return;
        }

        $baseline = $this->columnMap($before);
        $candidate = $this->columnMap($after);
        $removed = array_diff_key($baseline, $candidate);
        $added = array_diff_key($candidate, $baseline);

        if ($detectRenames) {
            [$removed, $added] = $this->renameColumns($before->name, $removed, $added, $category);
        }

        foreach ($removed as $column) {
            $this->add(
                $category,
                $before->name . '.' . $column->name,
                $before->name,
                ChangeType::Removed,
                null,
                null,
                null,
                $category === DifferenceCategory::View ? Severity::Medium : Severity::High,
                Confidence::High,
                'The column was removed. Stored values were not read, so data loss is potential rather than confirmed.',
            );
        }

        foreach ($added as $column) {
            $safe = $column->nullable || $column->defaultPresent;
            $this->add(
                $category,
                $after->name . '.' . $column->name,
                $after->name,
                ChangeType::Added,
                null,
                null,
                null,
                $safe || $category === DifferenceCategory::View ? Severity::Low : Severity::High,
                Confidence::High,
                $safe
                    ? 'A nullable column or a column with a default was added. Existing rows were not read.'
                    : 'A required column without a default was added. A deployment can fail when the table already has rows. Rows were not counted.',
            );
        }

        foreach (array_intersect_key($baseline, $candidate) as $name => $left) {
            $this->column($before->name, $left, $candidate[$name], $category);
        }
    }

    /**
     * @return array<string, SnapshotColumn>
     */
    private function columnMap(SnapshotObject $object): array
    {
        $map = [];

        foreach ($object->columns as $column) {
            $map[$column->name] = $column;
        }

        return $map;
    }

    /**
     * @param array<string, SnapshotColumn> $removed
     * @param array<string, SnapshotColumn> $added
     *
     * @return array{0: array<string, SnapshotColumn>, 1: array<string, SnapshotColumn>}
     */
    private function renameColumns(string $table, array $removed, array $added, DifferenceCategory $category): array
    {
        $removedBySignature = [];
        $addedBySignature = [];

        foreach ($removed as $column) {
            $removedBySignature[$column->signature()][] = $column;
        }

        foreach ($added as $column) {
            $addedBySignature[$column->signature()][] = $column;
        }

        foreach ($removed as $name => $column) {
            $signature = $column->signature();

            if (count($removedBySignature[$signature] ?? []) !== 1 || count($addedBySignature[$signature] ?? []) !== 1) {
                continue;
            }

            $match = $addedBySignature[$signature][0];
            $this->add(
                $category,
                $table . '.' . $column->name,
                $table,
                ChangeType::Renamed,
                'name',
                $column->name,
                $match->name,
                Severity::Medium,
                Confidence::Medium,
                'This match is a heuristic rename, not a confirmed rename.',
                true,
                true,
            );
            unset($removed[$name], $added[$match->name]);
        }

        return [$removed, $added];
    }

    private function column(string $table, SnapshotColumn $before, SnapshotColumn $after, DifferenceCategory $category): void
    {
        $name = $table . '.' . $before->name;

        if ($before->typeName !== $after->typeName) {
            $this->add($category, $name, $table, ChangeType::Modified, 'type_name', $before->typeName, $after->typeName, Severity::High, Confidence::Medium, 'The column type changed. Whether existing values still fit was not checked.');
        } elseif ($before->normalizedType !== $after->normalizedType) {
            $this->add($category, $name, $table, ChangeType::Modified, 'normalized_type', $before->normalizedType, $after->normalizedType, Severity::Medium, Confidence::High, 'The normalized column type changed.');
        }

        $this->number($category, $name, $table, 'character_length', $before->characterLength, $after->characterLength, true);
        $this->number($category, $name, $table, 'numeric_precision', $before->numericPrecision, $after->numericPrecision, true);
        $this->number($category, $name, $table, 'numeric_scale', $before->numericScale, $after->numericScale, true);

        if ($before->nullable !== $after->nullable) {
            $tightened = $before->nullable && ! $after->nullable;
            $this->add(
                $category,
                $name,
                $table,
                ChangeType::Modified,
                'nullable',
                ComparisonText::display($before->nullable),
                ComparisonText::display($after->nullable),
                $tightened ? Severity::High : Severity::Low,
                Confidence::High,
                $tightened
                    ? 'Nullability changed from nullable to required. Existing rows were not read.'
                    : 'Nullability changed from required to nullable.',
            );
        }

        if ($before->defaultPresent !== $after->defaultPresent || $before->defaultSensitive !== $after->defaultSensitive) {
            $this->add($category, $name, $table, ChangeType::Modified, 'default_present', ComparisonText::display($before->defaultPresent), ComparisonText::display($after->defaultPresent), Severity::Medium, Confidence::High, 'The column default presence changed. Stored values were not read.');
        } elseif ($before->defaultValue !== $after->defaultValue && ! $before->defaultSensitive) {
            $this->add($category, $name, $table, ChangeType::Modified, 'default_value', ComparisonText::display($before->defaultValue), ComparisonText::display($after->defaultValue), Severity::Medium, Confidence::High, 'The column default changed. Stored values were not read.');
        }

        $this->flag($category, $name, $table, 'auto_increment', $before->autoIncrement, $after->autoIncrement, 'The auto-increment status changed.');
        $this->flag($category, $name, $table, 'unsigned', $before->unsigned, $after->unsigned, 'The unsigned status changed.');
        $this->flag($category, $name, $table, 'generated', $before->generated, $after->generated, 'The generated column status changed.');

        if ($before->generation !== $after->generation && $before->generation !== null && $after->generation !== null) {
            $this->add($category, $name, $table, ChangeType::Modified, 'generation', $before->generation, $after->generation, Severity::Medium, Confidence::High, 'The generated column storage changed.');
        }
    }

    private function number(DifferenceCategory $category, string $name, string $table, string $field, ?int $before, ?int $after, bool $narrows): void
    {
        if ($before === null || $after === null || $before === $after) {
            return;
        }

        $narrowed = $narrows && $after < $before;
        $this->add(
            $category,
            $name,
            $table,
            ChangeType::Modified,
            $field,
            (string) $before,
            (string) $after,
            $narrowed ? Severity::High : Severity::Low,
            Confidence::High,
            $narrowed
                ? 'The column is narrower. Values were not read, so truncation is potential rather than confirmed.'
                : 'The column size increased. Values were not read.',
        );
    }

    private function flag(DifferenceCategory $category, string $name, string $table, string $field, bool $before, bool $after, string $explanation): void
    {
        if ($before === $after) {
            return;
        }

        $this->add($category, $name, $table, ChangeType::Modified, $field, ComparisonText::display($before), ComparisonText::display($after), Severity::Medium, Confidence::High, $explanation);
    }

    private function primaryKey(SnapshotObject $before, SnapshotObject $after): void
    {
        if ($before->primaryColumns === $after->primaryColumns) {
            return;
        }

        $removed = $before->primaryColumns !== [] && $after->primaryColumns === [];
        $this->add(
            DifferenceCategory::PrimaryKey,
            $before->name,
            $before->name,
            $before->primaryColumns === [] ? ChangeType::Added : ($removed ? ChangeType::Removed : ChangeType::Modified),
            'columns',
            ComparisonText::join($before->primaryColumns),
            ComparisonText::join($after->primaryColumns),
            $removed || $before->primaryColumns !== [] ? Severity::High : Severity::Medium,
            Confidence::High,
            $removed
                ? 'The primary key was removed.'
                : 'The primary key columns changed. Column order is part of the key.',
        );
    }

    private function indexes(SnapshotObject $before, SnapshotObject $after): void
    {
        $baseline = [];
        $candidate = [];

        foreach ($before->indexes as $index) {
            $baseline[$index->name] = $index;
        }

        foreach ($after->indexes as $index) {
            $candidate[$index->name] = $index;
        }

        foreach (array_diff_key($baseline, $candidate) as $index) {
            $this->add(DifferenceCategory::Index, $before->name . '.' . $index->name, $before->name, ChangeType::Removed, null, null, null, Severity::Medium, Confidence::High, $index->unique ? 'A unique index was removed.' : 'An index was removed.');
        }

        foreach (array_diff_key($candidate, $baseline) as $index) {
            $this->add(
                DifferenceCategory::Index,
                $after->name . '.' . $index->name,
                $after->name,
                ChangeType::Added,
                null,
                null,
                null,
                $index->unique ? Severity::Medium : Severity::Low,
                Confidence::High,
                $index->unique
                    ? 'A unique index was added. Duplicate values were not checked.'
                    : 'An index was added. Locking behavior depends on the database engine and table size and was not measured.',
            );
        }

        foreach (array_intersect_key($baseline, $candidate) as $name => $left) {
            $this->compareIndex($before->name, $left, $candidate[$name]);
        }
    }

    private function compareIndex(string $table, SnapshotIndex $before, SnapshotIndex $after): void
    {
        $name = $table . '.' . $before->name;

        if ($before->unique !== $after->unique) {
            $this->add(DifferenceCategory::Index, $name, $table, ChangeType::Modified, 'unique', ComparisonText::display($before->unique), ComparisonText::display($after->unique), Severity::Medium, Confidence::High, 'The index uniqueness changed. Existing values were not checked.');
        }

        if ($before->type !== null && $after->type !== null && $before->type !== $after->type) {
            $this->add(DifferenceCategory::Index, $name, $table, ChangeType::Modified, 'type', $before->type, $after->type, Severity::Medium, Confidence::High, 'The index type changed.');
        }

        if (! $before->expression && ! $after->expression && $before->columns !== $after->columns) {
            $this->add(DifferenceCategory::Index, $name, $table, ChangeType::Modified, 'columns', ComparisonText::join($before->columns), ComparisonText::join($after->columns), Severity::Medium, Confidence::High, 'The index columns changed.');
        }

        if ($before->expression !== $after->expression) {
            $this->add(DifferenceCategory::Index, $name, $table, ChangeType::Modified, 'expression', ComparisonText::display($before->expression), ComparisonText::display($after->expression), Severity::Medium, Confidence::Medium, 'Index expression metadata changed. Expression text is not compared.');
        }
    }

    private function references(SnapshotObject $before, SnapshotObject $after): void
    {
        $baseline = [];
        $candidate = [];

        foreach ($before->references as $foreignKey) {
            $baseline[$foreignKey->name] = $foreignKey;
        }

        foreach ($after->references as $foreignKey) {
            $candidate[$foreignKey->name] = $foreignKey;
        }

        foreach (array_diff_key($baseline, $candidate) as $foreignKey) {
            $this->add(DifferenceCategory::ForeignKey, $before->name . '.' . $foreignKey->name, $before->name, ChangeType::Removed, null, null, null, Severity::Medium, Confidence::High, 'A foreign key was removed. Referential integrity may be weaker. Rows were not checked.');
        }

        foreach (array_diff_key($candidate, $baseline) as $foreignKey) {
            $this->add(DifferenceCategory::ForeignKey, $after->name . '.' . $foreignKey->name, $after->name, ChangeType::Added, null, null, null, Severity::Medium, Confidence::Medium, 'A foreign key was added. Orphaned rows were not checked, so this is not proof the migration will fail.');
        }

        foreach (array_intersect_key($baseline, $candidate) as $name => $left) {
            $this->foreignKey($before->name, $left, $candidate[$name]);
        }
    }

    private function foreignKey(string $table, SnapshotForeignKey $before, SnapshotForeignKey $after): void
    {
        $name = $table . '.' . $before->name;
        $this->text($name, $table, 'columns', ComparisonText::join($before->columns), ComparisonText::join($after->columns), 'The foreign key columns changed.');
        $this->text($name, $table, 'referenced_table', $before->referencedTable, $after->referencedTable, 'The referenced table changed.');
        $this->text($name, $table, 'referenced_columns', ComparisonText::join($before->referencedColumns), ComparisonText::join($after->referencedColumns), 'The referenced columns changed.');
        $this->text($name, $table, 'on_update', $before->onUpdate, $after->onUpdate, 'The on update action changed.');
        $this->text($name, $table, 'on_delete', $before->onDelete, $after->onDelete, 'The on delete action changed.');
    }

    private function text(string $name, string $table, string $field, ?string $before, ?string $after, string $explanation): void
    {
        if ($before === $after) {
            return;
        }

        $this->add(DifferenceCategory::ForeignKey, $name, $table, ChangeType::Modified, $field, $before, $after, Severity::Medium, Confidence::High, $explanation);
    }

    private function add(
        DifferenceCategory $category,
        string $objectName,
        ?string $table,
        ChangeType $change,
        ?string $field,
        ?string $before,
        ?string $after,
        Severity $severity,
        Confidence $confidence,
        string $explanation,
        bool $complete = true,
        bool $heuristic = false,
    ): void {
        if (count($this->differences) >= self::DIFFERENCE_LIMIT) {
            $this->capped = true;

            return;
        }

        $id = $category->value . '.' . $objectName . '.' . $change->value;

        if ($field !== null) {
            $id .= '.' . $field;
        }

        $this->differences[] = new SchemaDifference(
            $id,
            $category,
            $category,
            $objectName,
            $table,
            $change,
            $field,
            $before,
            $after,
            $severity,
            $confidence,
            $explanation,
            $complete,
            $heuristic,
        );
    }
}
