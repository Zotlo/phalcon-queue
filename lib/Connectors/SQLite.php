<?php

namespace Phalcon\Queue\Connectors;

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Queue\Exceptions\DatabaseException;

class SQLite extends PDOStorage
{
    /**
     * @param string $queue
     * @return object|bool
     * @throws DatabaseException
     */
    public function getPendingJob(string $queue = 'default'): object|false
    {
        try {
            return $this->db->query('SELECT * FROM jobs WHERE queue = :queue AND available_at <= :available_at AND reserved_at IS NULL ORDER BY RANDOM()',
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
            return $this->db->execute('INSERT INTO jobs_locked (lock_key) VALUES (:lock_key)', [
                'lock_key' => $key,
            ]);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $key
     * @return bool
     * @throws DatabaseException
     */
    public function unlock(string $key): bool
    {
        try {
            return $this->db->execute('DELETE FROM jobs_locked WHERE lock_key = :lock_key', [
                'lock_key' => $key,
            ]);
        } catch (\Throwable $exception) {
            return false;
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
        $this->migrateLockedJobsTable();
        $this->migrateFailedJobsTable();
    }

    /**
     * @return void
     * @throws DatabaseException
     */
    private function migrateJobsTable(): void
    {
        $tableName = 'jobs';
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
                        'type'    => Column::TYPE_TEXT,
                        'notNull' => true,
                    ]
                ),
                new Column('attempts',
                    [
                        'type'    => Column::TYPE_INTEGER,
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
            @$this->db->createTable($tableName, null, $definition);
        } catch (\Throwable $exception) {
            throw new DatabaseException('Failed to create table ' . $tableName . ': ' . $exception->getMessage());
        }
    }

    /**
     * @return void
     * @throws DatabaseException
     */
    private function migrateLockedJobsTable(): void
    {
        $tableName = 'jobs_locked';
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
                new Column('lock_key',
                    [
                        'type'    => Column::TYPE_VARCHAR,
                        'size'    => 100,
                        'notNull' => true,
                    ]
                )
            ],
            'indexes' => [
                new Index('job_lock_key_index', ['lock_key'], 'UNIQUE'),
            ]
        ];

        if ($this->db->tableExists($tableName)) {
            return;
        }

        try {
            @$this->db->createTable($tableName, null, $definition);
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
                        'type'    => Column::TYPE_TEXT,
                        'notNull' => true,
                    ]
                ),
                new Column('attempts',
                    [
                        'type'    => Column::TYPE_INTEGER,
                        'notNull' => true,
                    ]
                ),
                new Column('exception',
                    [
                        'type'    => Column::TYPE_TEXT,
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
            @$this->db->createTable($tableName, null, $definition);
        } catch (\Throwable $exception) {
            throw new DatabaseException('Failed to create table ' . $tableName . ': ' . $exception->getMessage());
        }
    }
}