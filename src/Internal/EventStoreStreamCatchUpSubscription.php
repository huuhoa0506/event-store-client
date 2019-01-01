<?php

/**
 * This file is part of `prooph/event-store-client`.
 * (c) 2018-2019 prooph software GmbH <contact@prooph.de>
 * (c) 2018-2019 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStoreClient\Internal;

use Amp\Delayed;
use Amp\Promise;
use Generator;
use Prooph\EventStore\AsyncCatchUpSubscriptionDropped;
use Prooph\EventStore\AsyncEventStoreConnection;
use Prooph\EventStore\AsyncEventStoreStreamCatchUpSubscription;
use Prooph\EventStore\CatchUpSubscriptionSettings;
use Prooph\EventStore\EventAppearedOnAsyncCatchupSubscription;
use Prooph\EventStore\Exception\OutOfRangeException;
use Prooph\EventStore\Exception\StreamDeletedException;
use Prooph\EventStore\LiveProcessingStartedOnAsyncCatchUpSubscription;
use Prooph\EventStore\ResolvedEvent;
use Prooph\EventStore\SliceReadStatus;
use Prooph\EventStore\StreamEventsSlice;
use Prooph\EventStore\SubscriptionDropReason;
use Prooph\EventStore\UserCredentials;
use Psr\Log\LoggerInterface as Logger;
use Throwable;
use function Amp\call;

class EventStoreStreamCatchUpSubscription
    extends EventStoreCatchUpSubscription
    implements AsyncEventStoreStreamCatchUpSubscription
{
    /** @var int */
    private $nextReadEventNumber;
    /** @var int */
    private $lastProcessedEventNumber;

    /**
     * @internal
     */
    public function __construct(
        AsyncEventStoreConnection $connection,
        Logger $logger,
        string $streamId,
        ?int $fromEventNumberExclusive, // if null from the very beginning
        ?UserCredentials $userCredentials,
        EventAppearedOnAsyncCatchupSubscription $eventAppeared,
        ?LiveProcessingStartedOnAsyncCatchUpSubscription $liveProcessingStarted,
        ?AsyncCatchUpSubscriptionDropped $subscriptionDropped,
        CatchUpSubscriptionSettings $settings
    ) {
        parent::__construct(
            $connection,
            $logger,
            $streamId,
            $userCredentials,
            $eventAppeared,
            $liveProcessingStarted,
            $subscriptionDropped,
            $settings
        );

        $this->lastProcessedEventNumber = $fromEventNumberExclusive ?? -1;
        $this->nextReadEventNumber = $fromEventNumberExclusive ?? 0;
    }

    public function lastProcessedEventNumber(): int
    {
        return $this->lastProcessedEventNumber;
    }

    /** @return Promise<void> */
    protected function readEventsTillAsync(
        AsyncEventStoreConnection $connection,
        bool $resolveLinkTos,
        ?UserCredentials $userCredentials,
        ?int $lastCommitPosition,
        ?int $lastEventNumber
    ): Promise {
        return $this->readEventsInternalAsync($connection, $resolveLinkTos, $userCredentials, $lastEventNumber);
    }

    /** @return Promise<void> */
    private function readEventsInternalAsync(
        AsyncEventStoreConnection $connection,
        bool $resolveLinkTos,
        ?UserCredentials $userCredentials,
        ?int $lastEventNumber
    ): Promise {
        return call(function () use ($connection, $resolveLinkTos, $userCredentials, $lastEventNumber): Generator {
            do {
                $slice = yield $connection->readStreamEventsForwardAsync(
                    $this->streamId(),
                    $this->nextReadEventNumber,
                    $this->readBatchSize,
                    $resolveLinkTos,
                    $userCredentials
                );

                $shouldStopOrDone = yield $this->readEventsCallbackAsync($slice, $lastEventNumber);
            } while (! $shouldStopOrDone);
        });
    }

    /** @return Promise<bool> */
    private function readEventsCallbackAsync(StreamEventsSlice $slice, ?int $lastEventNumber): Promise
    {
        return call(function () use ($slice, $lastEventNumber): Generator {
            $shouldStopOrDone = $this->shouldStop || yield $this->processEventsAsync($lastEventNumber, $slice);

            if ($shouldStopOrDone && $this->verbose) {
                $this->log->debug(\sprintf(
                    'Catch-up Subscription %s to %s: finished reading events, nextReadEventNumber = %d',
                    $this->subscriptionName(),
                    $this->isSubscribedToAll() ? '<all>' : $this->streamId(),
                    $this->nextReadEventNumber
                ));
            }

            return $shouldStopOrDone;
        });
    }

    /** @return Promise<bool> */
    private function processEventsAsync(?int $lastEventNumber, StreamEventsSlice $slice): Promise
    {
        return call(function () use ($lastEventNumber, $slice): Generator {
            switch ($slice->status()->value()) {
                case SliceReadStatus::SUCCESS:
                    foreach ($slice->events() as $e) {
                        yield $this->tryProcessAsync($e);
                    }
                    $this->nextReadEventNumber = $slice->nextEventNumber();
                    $done = (null === $lastEventNumber) ? $slice->isEndOfStream() : $slice->nextEventNumber() > $lastEventNumber;

                    break;
                case SliceReadStatus::STREAM_NOT_FOUND:
                    if (null !== $lastEventNumber && $lastEventNumber !== -1) {
                        throw new \Exception(\sprintf(
                            'Impossible: stream %s disappeared in the middle of catching up subscription %s',
                            $this->streamId(),
                            $this->subscriptionName()
                        ));
                    }

                    $done = true;

                    break;
                case SliceReadStatus::STREAM_DELETED:
                    throw StreamDeletedException::with($this->streamId());
                default:
                    throw new OutOfRangeException(\sprintf(
                        'Unexpected SliceReadStatus "%s" received',
                        $slice->status()->name()
                    ));
            }

            if (! $done && $slice->isEndOfStream()) {
                yield new Delayed(1000); // we are waiting for server to flush its data
            }

            return $done;
        });
    }

    protected function tryProcessAsync(ResolvedEvent $e): Promise
    {
        return call(function () use ($e): Generator {
            $processed = false;

            if ($e->originalEventNumber() > $this->lastProcessedEventNumber) {
                try {
                    yield ($this->eventAppeared)($this, $e);
                } catch (Throwable $ex) {
                    $this->dropSubscription(SubscriptionDropReason::eventHandlerException(), $ex);
                }

                $this->lastProcessedEventNumber = $e->originalEventNumber();
                $processed = true;
            }

            if ($this->verbose) {
                $this->log->debug(\sprintf(
                    'Catch-up Subscription %s to %s: %s event (%s, %d, %s @ %d)',
                    $this->subscriptionName(),
                    $this->isSubscribedToAll() ? '<all>' : $this->streamId(),
                    $processed ? 'processed' : 'skipping',
                    $e->originalEvent()->eventStreamId(),
                    $e->originalEvent()->eventNumber(),
                    $e->originalEvent()->eventType(),
                    $e->originalEventNumber()
                ));
            }
        });
    }
}
