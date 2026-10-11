<?php

declare(strict_types=1);

namespace Beacon\Security;

enum AccessMode: string
{
    case Read = 'read';
    case Write = 'write';
}
