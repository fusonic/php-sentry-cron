<?php

/*
 * Copyright (c) Fusonic GmbH. All rights reserved.
 * Licensed under the MIT License. See LICENSE file in the project root for license information.
 */

declare(strict_types=1);

namespace Fusonic\SentryCron;

interface AsyncCheckInScheduleEventInterface
{
    public function isFinished(): bool;

    public function markAsFinished(): void;

    /**
     * @internal Used by {@see SentrySchedulerEventSubscriber}
     */
    public function getCheckInId(): ?string;

    /**
     * @internal Used by {@see SentrySchedulerEventSubscriber}
     */
    public function setCheckInId(?string $checkId): void;
}
