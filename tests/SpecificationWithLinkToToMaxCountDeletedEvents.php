<?php
/**
 * This file is part of the prooph/event-store-client.
 * (c) 2018-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2018-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStoreClient;

use Generator;
use Prooph\EventStoreClient\Common\SystemEventTypes;
use Prooph\EventStoreClient\EventData;
use Prooph\EventStoreClient\EventId;
use Prooph\EventStoreClient\ExpectedVersion;
use Prooph\EventStoreClient\Internal\UuidGenerator;
use Prooph\EventStoreClient\StreamMetadata;

trait SpecificationWithLinkToToMaxCountDeletedEvents
{
    use SpecificationWithConnection;

    /** @var string */
    protected $linkedStreamName;
    /** @var string */
    protected $deletedStreamName;

    protected function given(): Generator
    {
        $creds = DefaultData::adminCredentials();

        $this->deletedStreamName = UuidGenerator::generate();
        $this->linkedStreamName = UuidGenerator::generate();

        yield $this->conn->appendToStreamAsync(
            $this->deletedStreamName,
            ExpectedVersion::Any,
            [
                new EventData(EventId::generate(), 'testing1', true, \json_encode(['foo' => 4])),
            ],
            $creds
        );

        yield $this->conn->setStreamMetadataAsync(
            $this->deletedStreamName,
            ExpectedVersion::Any,
            new StreamMetadata(2)
        );

        yield $this->conn->appendToStreamAsync(
            $this->deletedStreamName,
            ExpectedVersion::Any,
            [
                new EventData(EventId::generate(), 'testing2', true, \json_encode(['foo' => 4])),
            ]
        );

        yield $this->conn->appendToStreamAsync(
            $this->deletedStreamName,
            ExpectedVersion::Any,
            [
                new EventData(EventId::generate(), 'testing3', true, \json_encode(['foo' => 4])),
            ]
        );

        $this->conn->appendToStreamAsync(
            $this->linkedStreamName,
            ExpectedVersion::Any,
            [
                new EventData(EventId::generate(), SystemEventTypes::LinkTo, false, '0@' . $this->deletedStreamName),
            ],
            $creds
        );
    }
}