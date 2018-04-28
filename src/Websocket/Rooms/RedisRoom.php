<?php

namespace SwooleTW\Http\Websocket\Rooms;

use Predis\Client as RedisClient;
use SwooleTW\Http\Websocket\Rooms\RoomContract;

class RedisRoom implements RoomContract
{
    const PREFIX = 'swoole:';

    protected $redis;

    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function prepare(RedisClient $redis = null)
    {
        $this->setRedis($redis);
        $this->cleanRooms();
    }

    /**
     * Set redis client.
     */
    public function setRedis(RedisClient $redis = null)
    {
        $server = $this->config['server'] ?? [];
        $options = $this->config['options'] ?? [];

        if ($redis) {
            $this->redis = $redis;
        } else {
            $this->redis = new RedisClient($server, $options);
        }
    }

    /**
     * Get redis client.
     */
    public function getRedis()
    {
        return $this->redis;
    }

    public function add(int $fd, string $room)
    {
        $this->addAll($fd, [$room]);
    }

    public function addAll(int $fd, array $roomNames)
    {
        $this->addValue($fd, $roomNames, 'sids');

        foreach ($roomNames as $room) {
            $this->addValue($room, [$fd], 'rooms');
        }
    }

    public function delete(int $fd, string $room)
    {
        $this->deleteAll($fd, [$room]);
    }

    public function deleteAll(int $fd, array $roomNames = [])
    {
        $roomNames = count($roomNames) ? $roomNames : $this->getRooms($fd);
        $this->removeValue($fd, $roomNames, 'sids');

        foreach ($roomNames as $room) {
            $this->removeValue($room, [$fd], 'rooms');
        }
    }

    public function addValue($key, array $values, string $table)
    {
        $this->checkTable($table);
        $redisKey = $this->getKey($key, $table);

        $this->redis->pipeline(function ($pipe) use ($redisKey, $values) {
            foreach ($values as $value) {
                $pipe->sadd($redisKey, $value);
            }
        });

        return $this;
    }

    public function removeValue($key, array $values, string $table)
    {
        $this->checkTable($table);
        $redisKey = $this->getKey($key, $table);

        $this->redis->pipeline(function ($pipe) use ($redisKey, $values) {
            foreach ($values as $value) {
                $pipe->srem($redisKey, $value);
            }
        });

        return $this;
    }

    public function getClients(string $room)
    {
        return $this->getValue($room, 'rooms');
    }

    public function getRooms(int $fd)
    {
        return $this->getValue($fd, 'sids');
    }

    protected function checkTable(string $table)
    {
        if (! in_array($table, ['rooms', 'sids'])) {
            throw new \InvalidArgumentException('invalid table name.');
        }
    }

    public function getValue(string $key, string $table)
    {
        $this->checkTable($table);

        return $this->redis->smembers($this->getKey($key, $table));
    }

    public function getKey(string $key, string $table)
    {
        return static::PREFIX . "{$table}:{$key}";
    }

    protected function cleanRooms()
    {
        $keys = $this->redis->keys(static::PREFIX . '*');
        if (count($keys)) {
            $this->redis->del($keys);
        }
    }
}
