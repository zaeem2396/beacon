<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * A database operation recognized in migration source.
 *
 * Recognition is syntactic. The operation has not been executed.
 */
enum OperationKind: string
{
    case CreateTable = 'create_table';
    case DropTable = 'drop_table';
    case RenameTable = 'rename_table';
    case AddColumn = 'add_column';
    case DropColumn = 'drop_column';
    case RenameColumn = 'rename_column';
    case ChangeColumn = 'change_column';
    case AddIndex = 'add_index';
    case AddUnique = 'add_unique';
    case DropIndex = 'drop_index';
    case AddForeign = 'add_foreign';
    case DropForeign = 'drop_foreign';
    case Truncate = 'truncate';
    case UpdateRows = 'update_rows';
    case DeleteRows = 'delete_rows';
    case ReadRows = 'read_rows';
    case RawSql = 'raw_sql';
    case DynamicSql = 'dynamic_sql';
    case Transaction = 'transaction';
}
