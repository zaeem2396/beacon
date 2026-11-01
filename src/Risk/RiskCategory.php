<?php

declare(strict_types=1);

namespace Beacon\Risk;

/**
 * The kind of harm a finding describes.
 */
enum RiskCategory: string
{
    case DataLoss = 'data_loss';
    case Locking = 'locking';
    case Compatibility = 'compatibility';
    case ExistingData = 'existing_data';
    case Performance = 'performance';
    case ForeignKey = 'foreign_key';
    case Transaction = 'transaction';
}
