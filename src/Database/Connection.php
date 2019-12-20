<?php

namespace Lightning\Database;

use mysqli;
use mysqli_result;
use Throwable;
use RuntimeException;
use Lightning\Database\QueryResult;
use function LIghtning\container;
use React\Promise\{Deferred, PromiseInterface};

class Connection
{
    const STATE_CLOSE = -1;
    const STATE_IDLE = 1;
    const STATE_WORKING = 2;
    const STATE_OCCUPY = 4;

    const ACTION_STATE_CHANGED = 'state_changed';

    const FETCH_MODES = ['fetch_all', 'fetch_row'];

    private $eventManager;
    private $connectionName;
    private $role;
    /** @var mysqli $link */
    private $link;
    private $credential = [];
    private $profile = [];
    private $state = self::STATE_CLOSE;
    private $stateTimeStart = 0;
    private $deferred;
    private $fetchMode;

    public function __construct(string $connection_name, array $options)
    {
        $this->eventManager = container()->get('event-manager');
        $this->connectionName = $connection_name;
        $this->role = $options['role'];
        unset($options['role']);
        $this->credential = $options;
        $this->updateProfile('time_created', time());
    }

    public function getLink(): ?mysqli
    {
        return $this->link;
    }

    public function getState()
    {
        return $this->state;
    }

    public function getStateDuration()
    {
        return floatval(bcsub(microtime(true), $this->stateTimeStart, 4));
    }

    public function escape($value)
    {
        $this->link->real_escape_string($value);
    }

    public function query(string $sql, string $fetch_mode, array $params = []): PromiseInterface
    {
        if ($this->state !== self::STATE_IDLE) {
            throw new RuntimeException('Connection is not ready for query yet');
        }

        if (! empty($params)) {
            $sql = $this->bindSql($sql, $params);
        }

        $this->fetchMode = $fetch_mode;
        $this->updateProfile('time_query', time());
        $this->deferred = new Deferred();
        $this->state = self::STATE_WORKING;
        $this->link->query($sql, MYSQLI_ASYNC);
        return $this->deferred->promise();
    }

    public function resolve($result)
    {
        $query_result = $this->fetchQueryResult($result);
        $this->deferred->resolve($query_result);
        $this->reset();
        $this->changeState(self::STATE_IDLE);
    }

    public function reject(Throwable $error)
    {
        $this->deferred->reject($error);
        $this->reset();
        $this->changeState(self::STATE_IDLE);
    }

    public static function eventName(string $action, $state): string
    {
        $class_name = str_replace("\\", "_", self::class);
        return implode('.', [$class_name, $action, $state]);
    }

    public function open(): bool
    {
        if ($this->state !== self::STATE_CLOSE) {
            return false;
        }

        $this->updateProfile('time_opened', time());
        $config = $this->credential;
        try {
            $this->link = new mysqli(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['dbname']
            );
            $this->changeState(self::STATE_IDLE);
            return true;
        } catch (Throwable $e) {
            //do log stuff
            return false;
        }
    }

    public function close()
    {
        if ($this->link !== null) {
            $this->link->close();
            $this->link = null;
        }
        $this->changeState(self::STATE_CLOSE);
    }

    public function ping(): bool
    {
        if ($this->link === null) {
            return false;
        } else {
            return $this->link->ping();
        }
    }

    public function getProfile($field)
    {
        return isset($this->profile[$field]) ? $this->profile[$field] : null;
    }

    private function reset()
    {
        $this->deferred = null;
        $this->fetchMode = '';
    }

    private function bindSql(string $sql, array $params)
    {
        $search = [];
        $replace = [];
        foreach ($params as $key => $val) {
            if (is_string($val)) {
                $key = "'{$key}'";
                $val = $this->link->real_escape_string($val);
            }
            $search[] = $key;
            $replace[] = $val;
        }
        return str_replace($search, $replace, $sql);
    }

    private function fetchQueryResult($result): QueryResult
    {
        if ($result instanceof mysqli_result) {
            if ($this->fetchMode == 'fetch_row') {
                $data = $result->fetch_assoc();
            } elseif ($this->fetchMode == 'fetch_all') {
                $data = $result->fetch_all(MYSQLI_ASSOC);
            }
            $result->close();
            return new QueryResult($data);
        } else {
            return new QueryResult(null, $this->link->insert_id, $this->link->affected_rows);
        }
    }

    private function changeState($state)
    {
        $this->state = $state;
        $this->stateTimeStart = microtime(true);
        $this->eventManager->emit(
            self::eventName(self::ACTION_STATE_CHANGED, $state),
            [
                'connection_name' => $this->connectionName,
                'role' => $this->role,
                'connection' => $this
            ]
        );
    }

    private function updateProfile()
    {
        $param = func_get_args();
        if (count($param) == 1) {
            foreach ($param as $key => $val) {
                $this->profile[$key] = $val;
            }
        } else {
            $this->profile[$param[0]] = $param[1];
        }
        $this->profile['time_updated'] = time();
    }
}