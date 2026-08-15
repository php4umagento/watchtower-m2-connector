<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * The wire-level status enum, mirrored here so the connector's own code never
 * passes around a bare string for it. Values must stay byte-for-byte identical
 * to the ones the platform accepts.
 */
enum SignalStatus: string
{
    case Normal = 'NORMAL';
    case MildDrop = 'MILD_DROP';
    case SevereDrop = 'SEVERE_DROP';
    case MildSpike = 'MILD_SPIKE';
    case SevereSpike = 'SEVERE_SPIKE';
    case InsufficientData = 'INSUFFICIENT_DATA';
}
