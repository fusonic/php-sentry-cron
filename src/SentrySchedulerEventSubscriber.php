<?php

/*
 * Copyright (c) Fusonic GmbH. All rights reserved.
 * Licensed under the MIT License. See LICENSE file in the project root for license information.
 */

declare(strict_types=1);

namespace Fusonic\SentryCron;

use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

use function Sentry\captureCheckIn;
use function Symfony\Component\String\u;

/**
 * Event subscriber that triggers Sentry monitoring status checks for scheduled jobs.
 */
class SentrySchedulerEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var array<string, string>
     */
    private array $checkInIds = [];

    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PostRunEvent::class => 'onPostRun',
            PreRunEvent::class => 'onPreRun',
            FailureEvent::class => 'onFailure',
        ];
    }

    /**
     * @return class-string<TriggerInterface>[]
     */
    private static function supportedTriggers(): array
    {
        return [CronExpressionTrigger::class];
    }

    public function onPreRun(PreRunEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $messageId = $event->getMessageContext()->id;
        $trigger = $event->getMessageContext()->trigger;

        if ($trigger instanceof AbstractDecoratedTrigger) {
            $trigger = $trigger->inner();
        }

        if (!\in_array($trigger::class, self::supportedTriggers(), true)) {
            return;
        }

        $message = $event->getMessage();
        $attribute = $this->getMonitorConfigAttribute($message);

        $monitorConfig = new MonitorConfig(
            MonitorSchedule::crontab((string) $trigger),
            checkinMargin: $attribute?->checkinMargin,
            maxRuntime: $attribute?->maxRuntime,
            failureIssueThreshold: $attribute?->failureIssueThreshold,
            recoveryThreshold: $attribute?->maxRuntime,
        );

        $checkInId = captureCheckIn(
            slug: $this->getMessageSlug($message),
            status: CheckInStatus::inProgress(),
            monitorConfig: $monitorConfig,
        );

        if (null !== $checkInId) {
            $this->checkInIds[$messageId] = $checkInId;

            if ($message instanceof AsyncCheckInScheduleEventInterface) {
                $message->setCheckInId($checkInId);
            }
        }
    }

    public function onPostRun(PostRunEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $messageId = $event->getMessageContext()->id;
        $message = $event->getMessage();

        if ($message instanceof AsyncCheckInScheduleEventInterface) {
            if (!$message->isFinished()) {
                return;
            }

            $checkInId = $message->getCheckInId();
        } else {
            $checkInId = $this->checkInIds[$messageId] ?? null;
        }

        if (null !== $checkInId) {
            captureCheckIn(
                slug: $this->getMessageSlug($event->getMessage()),
                status: CheckInStatus::ok(),
                checkInId: $checkInId,
            );

            unset($this->checkInIds[$messageId]);
        }
    }

    public function onFailure(FailureEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $messageId = $event->getMessageContext()->id;
        $message = $event->getMessage();

        if ($message instanceof AsyncCheckInScheduleEventInterface) {
            $checkInId = $message->getCheckInId();
        } else {
            $checkInId = $this->checkInIds[$messageId] ?? null;
        }

        if (null !== $checkInId) {
            captureCheckIn(
                slug: $this->getMessageSlug($event->getMessage()),
                status: CheckInStatus::error()
            );

            unset($this->checkInIds[$messageId]);
        }
    }

    protected function getMessageSlug(object $message): string
    {
        return (string) u(self::getClassBasename($message::class))->snake();
    }

    private function getMonitorConfigAttribute(object $message): ?SentryMonitorConfig
    {
        $reflection = new \ReflectionClass($message);
        $attributes = $reflection->getAttributes(SentryMonitorConfig::class);

        if (0 === \count($attributes)) {
            return null;
        }

        /** @var \ReflectionAttribute<SentryMonitorConfig> $attribute */
        $attribute = $attributes[0];

        return $attribute->newInstance();
    }

    private static function getClassBasename(string $className): string
    {
        /** @var string $basename */
        $basename = strrchr($className, '\\');

        return substr($basename, 1);
    }
}
