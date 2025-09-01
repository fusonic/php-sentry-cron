<?php

/*
 * Copyright (c) Fusonic GmbH. All rights reserved.
 * Licensed under the MIT License. See LICENSE file in the project root for license information.
 */

declare(strict_types=1);

namespace Fusonic\SentryCron;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class SentryMonitorConfig
{
    public function __construct(
        public ?int $checkinMargin = null,
        public ?int $maxRuntime = null,
        public ?int $failureIssueThreshold = null,
        public ?int $recoveryThreshold = null,
    ) {
    }
}
