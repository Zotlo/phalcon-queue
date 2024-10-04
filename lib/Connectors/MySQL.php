<?php

namespace Phalcon\Queue\Connectors;

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Queue\Exceptions\DatabaseException;

class MySQL extends PDOStorage
{
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
                new Column('job_id',
                    [
                        'type'    => Column::TYPE_VARCHAR,
                        'size'    => 40,
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
                        'type'    => Column::TYPE_VARCHAR,
                        'size'    => 40,
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