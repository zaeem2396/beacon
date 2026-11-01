<?php

declare(strict_types=1);

namespace Beacon\Comparison;

enum DifferenceCategory: string
{
    case Table = 'table';
    case Column = 'column';
    case PrimaryKey = 'primary_key';
    case Index = 'index';
    case ForeignKey = 'foreign_key';
    case View = 'view';
    case Metadata = 'metadata';
}
