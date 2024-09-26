<?php

namespace Phalcon\Queue\Connectors;

use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Di\Di;
use Phalcon\Queue\Exceptions\DatabaseException;
use Phalcon\Queue\Jobs\Job;

class MySQL extends PDOStorage
{
    /**
     * Database Interface
     *
     * @var DatabaseInterface $db
     */
    protected DatabaseInterface $db;

    public function __construct()
    {
        $this->db = Di::getDefault()->get('db');
    }

    /**
     * @param object $job
     * @return bool
     * @throws DatabaseException
     */
    public function markAsProcessing(object $job): bool
    {
        try {
            $job->attempts += 1;
            $update = $this->db->query('UPDATE jobs SET reserved_at = :reserved_at, attempts = :attempts WHERE id = :id', [
                'id'          => $job->id,
                'reserved_at' => gmdate("Y-m-d H:i:s"),
                'attempts'    => $job->attempts
            ]);

            if ($update) {
                return true;
            }
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return false;
    }

    /**
     * @param object $job
     * @param object $exception
     * @return bool
     * @throws DatabaseException
     */
    public function markAsFailed(object $job, object $exception): bool
    {
        try {
            $query = $this->db->query(
                'INSERT INTO jobs_failed (job_id, queue, payload, attempts, exception) VALUES (:jobId, :queue, :payload, :attempts, :exception)',
                [
                    'jobId'     => $job->id,
                    'queue'     => $job->queue,
                    'payload'   => $job->payload,
                    'attempts'  => $job->attempts,
                    'exception' => json_encode([
                            'class'   => get_class($exception),
                            'message' => $exception->getMessage(),
                            'code'    => $exception->getCode(),
                            'file'    => $exception->getFile(),
                            'line'    => $exception->getLine(),
                            'trace'   => $exception->getTrace(),
                        ]
                    ),
                ]);

            if ($query) {
                return true;
            }
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return false;
    }

    /**
     * @param object $job
     * @return bool
     * @throws DatabaseException
     */
    public function markAsCompleted(object $job): bool
    {
        try {
            $query = $this->db->query('DELETE FROM jobs WHERE id = :id', [
                'id' => $job->id,
            ])->fetch(\PDO::FETCH_OBJ);

            if ($query) {
                return true;
            }
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return false;
    }

    /**
     * @param string $key
     * @return bool
     * @throws DatabaseException
     */
    public function lock(string $key): bool
    {
        try {
            $lock = $this->db->query('SELECT GET_LOCK(:lockKey, 0) AS locked', [
                'lockKey' => $key,
            ])->fetch(\PDO::FETCH_OBJ);
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return isset($lock->locked) && $lock->locked;
    }

    /**
     * @param string $key
     * @return bool
     * @throws DatabaseException
     */
    public function unlock(string $key): bool
    {
        try {
            $lock = $this->db->query('SELECT RELEASE_LOCK(:lockKey, 0) AS unlocked', [
                'lockKey' => $key,
            ])->fetch(\PDO::FETCH_OBJ);
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return isset($lock->unlocked) && $lock->unlocked;
    }

    /**
     * @param Job $job
     * @return object|false
     * @throws DatabaseException
     */
    public function insertJob(Job $job): object|false
    {
        try {
            return $this->db->query(
                'INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES (:queue, :payload, :attempts, :available_at, :created_at)',
                [
                    'queue'        => $job->getQueue(),
                    'payload'      => serialize($job),
                    'attempts'     => 0,
                    'available_at' => gmdate('Y-m-d H:i:s', strtotime('+' . $job->getDelay() . ' seconds')),
                    'created_at'   => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param string $queue
     * @return object|bool
     * @throws DatabaseException
     */
    public function getPendingJob(string $queue = 'default'): object|false
    {
        try {
            return $this->db->query('SELECT * FROM jobs WHERE queue = :queue AND available_at <= :available_at AND reserved_at IS NULL ORDER BY RAND()',
                [
                    'queue'        => $queue,
                    'available_at' => gmdate("Y-m-d H:i:s"),
                ])->fetch(\PDO::FETCH_OBJ);
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param string $queue
     * @return array
     */
    public function getPendingJobs(string $queue = 'default'): array
    {
        try {
            return $this->db->query(
                'SELECT * FROM jobs WHERE queue = :queue AND available_at <= :available_at AND reserved_at IS NULL',
                [
                    'queue'        => $queue,
                    'available_at' => gmdate("Y-m-d H:i:s"),
                ]
            )->fetchAll(\PDO::FETCH_OBJ);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * Check & Migrate Jobs SQL Table
     *
     * @return void
     * @throws DatabaseException
     */
    public function checkTableAndMigrateIfNecessary(): void
    {
        if (!isset($this->db)) {
            throw new DatabaseException("Database connection is not set");
        }

        $this->migrateJobsTable();
        $this->migrateFailedJobsTable();
    }

    /**
     * @return void
     * @throws DatabaseException
     */
    private function migrateJobsTable(): void
    {
        $tableName = 'jobs';
        $schemaName = $this->db->getDescriptor()['dbname'];
        $definition = [
            'columns' => [
                new Column('id',
                    [
                        'type'          => Column::TYPE_BIGINTEGER,
                        'primary'       => true,
                        'autoIncrement' => true,
                        'notNull'       => true,
                    ]
                ),
                new Column('queue',
                    [
                        'type'    => Column::TYPE_VARCHAR,
                        'size'    => 255,
                        'notNull' => true,
                    ]
                ),
                new Column('payload',
                    [
                        'type'    => Column::TYPE_LONGTEXT,
                        'notNull' => true,
                    ]
                ),
                new Column('attempts',
                    [
                        'type'    => Column::TYPE_TINYINTEGER,
                        'notNull' => true,
                        'default' => 0,
                    ]
                ),
                new Column('reserved_at',
                    [
                        'type'    => Column::TYPE_DATETIME,
                        'notNull' => false,
                    ]
                ),
                new Column('available_at',
                    [
                        'type'    => Column::TYPE_DATETIME,
                        'notNull' => true,
                        'default' => 'CURRENT_TIMESTAMP'
                    ]
                ),
                new Column('created_at',
                    [
                        'type'    => Column::TYPE_DATETIME,
                        'notNull' => true,
                        'default' => 'CURRENT_TIMESTAMP'
                    ]
                ),
            ],
            'indexes' => [
                new Index('jobs_queue_index', ['queue']),
            ]
        ];

        if ($this->db->tableExists($tableName)) {
            return;
        }

        try {
            $this->db->createTable($tableName, $schemaName, $definition);
        } catch (\Throwable $exception) {
            throw new DatabaseException('Failed to create table ' . $tableName . ': ' . $exception->getMessage());
        }
    }

    /**
     * @return void
     * @throws DatabaseException
     */
    private function migrateFailedJobsTable(): void
    {
        $tableName = 'jobs_failed';
        $schemaName = $this->db->getDescriptor()['dbname'];
        $definition = [
            'columns' => [
                new Column('id',
                    [
                        'type'          => Column::TYPE_INTEGER,
                        'primary'       => true,
                        'autoIncrement' => true,
                        'notNull'       => true,
                    ]
                ),
                new Column('job_id',
                    [
                        'type'    => Column::TYPE_BIGINTEGER,
                        'notNull' => true,
                    ]
                ),
                new Column('queue',
                    [
                        'type'    => Column::TYPE_VARCHAR,
                        'size'    => 255,
                        'notNull' => true,
                    ]
                ),
                new Column('payload',
                    [
                        'type'    => Column::TYPE_LONGTEXT,
                        'notNull' => true,
                    ]
                ),
                new Column('attempts',
                    [
                        'type'    => Column::TYPE_TINYINTEGER,
                        'notNull' => true,
                    ]
                ),
                new Column('exception',
                    [
                        'type'    => Column::TYPE_LONGTEXT,
                        'notNull' => true,
                    ]
                ),
                new Column('failed_at',
                    [
                        'type'    => Column::TYPE_DATETIME,
                        'notNull' => true,
                        'default' => 'CURRENT_TIMESTAMP'
                    ]
                ),
            ],
            'indexes' => [
                new Index('job_id_index', ['job_id']),
            ]
        ];

        if ($this->db->tableExists($tableName)) {
            return;
        }

        try {
            $this->db->createTable($tableName, $schemaName, $definition);
        } catch (\Throwable $exception) {
            throw new DatabaseException('Failed to create table ' . $tableName . ': ' . $exception->getMessage());
        }
    }
}