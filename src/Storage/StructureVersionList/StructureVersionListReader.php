<?php

namespace Honeybee\RabbitMq3\Storage\StructureVersionList;

use Honeybee\Infrastructure\Config\SettingsInterface;
use Honeybee\Infrastructure\DataAccess\Storage\StorageReaderInterface;
use Honeybee\Infrastructure\DataAccess\Storage\StorageReaderIterator;
use Honeybee\Infrastructure\Migration\StructureVersion;
use Honeybee\Infrastructure\Migration\StructureVersionList;
use Honeybee\RabbitMq3\Storage\RabbitMqStorage;

class StructureVersionListReader extends RabbitMqStorage implements StorageReaderInterface
{
    public function read($identifier, SettingsInterface $settings = null)
    {
        $bindings = $this->getExchangeBindings();

        $versions = [];
        foreach ($bindings as $version) {
            if ($version['routing_key'] === $identifier) {
                $versions[] = $version['arguments'];
            }
        }

        if (empty($versions)) {
            return null;
        }

        return $this->createStructureVersionList($identifier, $versions);
    }

    public function readAll(SettingsInterface $settings = null)
    {
        $bindings = $this->getExchangeBindings();

        $versions = [];
        foreach ($bindings as $version) {
            $versions[$version['routing_key']][] = $version['arguments'];
        }

        $data = [];
        foreach ($versions as $identifier => $versionList) {
            $data[] = $this->createStructureVersionList($identifier, $versionList);
        }

        return $data;
    }

    public function getIterator()
    {
        return new StorageReaderIterator($this);
    }

    protected function createStructureVersionList($identifier, array $versions)
    {
        $structureVersionList = new StructureVersionList($identifier);

        // sort version list
        usort($versions, function ($a, $b) {
            return $a['version'] - $b['version'];
        });

        foreach ($versions as $version) {
            $structureVersionList->push(new StructureVersion($version));
        }

        return $structureVersionList;
    }
}
