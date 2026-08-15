<?php

declare(strict_types=1);

namespace Watchtower\Connector\Model\Api;

/**
 * Why a report was sent. Values must stay byte-for-byte identical to the ones
 * the platform accepts.
 */
enum ReportReason: string
{
    case Heartbeat = 'heartbeat';
    case Transition = 'transition';
}
