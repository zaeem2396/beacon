<?php

declare(strict_types=1);

namespace Beacon\Schema;

enum ObjectType: string
{
    case Table = 'table';
    case View = 'view';
}
