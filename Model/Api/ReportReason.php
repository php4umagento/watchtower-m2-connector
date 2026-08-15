<?php
/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

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
