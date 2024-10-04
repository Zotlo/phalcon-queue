<?php

namespace Phalcon\Queue\Connectors;

use PDO;
use Phalcon\Db\Adapter\AdapterInterface as DatabaseInterface;
use Phalcon\Di\Di;
use Phalcon\Queue\Exceptions\DatabaseException;
use Phalcon\Queue\Jobs\Job;
use Phalcon\Queue\Jobs\Status;

abstract class PDOStorage implements ConnectorInterface
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
                'INSERT INTO jobs_failed (job_id, queue, payload, attempts, exception) VALUES (:job_id, :queue, :payload, :attempts, :exception)',
                [
                    'job_id'    => $job->job_id,
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
     * @param Job $job
     * @return string
     * @throws DatabaseException
     */
    public function insertJob(Job $job): string
    {
        try {
            $serializedJob = serialize($job);

            $this->db->query(
                'INSERT INTO jobs (job_id, queue, payload, attempts, available_at, created_at) VALUES (:job_id, :queue, :payload, :attempts, :available_at, :created_at)',
                [
                    'job_id'       => $job->id,
                    'queue'        => $job->getQueue(),
                    'payload'      => $serializedJob,
                    'attempts'     => 0,
                    'available_at' => gmdate('Y-m-d H:i:s', strtotime('+' . $job->getDelay() . ' seconds')),
                    'created_at'   => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $exception) {
            throw new DatabaseException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return $this->db->lastInsertId();
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
     * @param Job $job
     * @return Status
     */
    public function getJobStatus(Job $job): Status
    {
        try {
            // Get Job
            $pendingJob = $this->db->query('SELECT * FROM jobs WHERE job_id = :job_id', [
                'job_id' => $job->id
            ])->fetch(PDO::FETCH_OBJ);

            // Search Job Failed
            if (empty($pendingJob)) {
                $failedJob = $this->db->query('SELECT * FROM jobs_failed WHERE job_id = :job_id', [
                    'job_id' => $job->id
                ])->fetch(PDO::FETCH_OBJ);

                if (!empty($failedJob)) {
                    return Status::FAILED;
                }
            } else {
                if (empty($pendingJob->reserved_at)) {
                    return Status::PENDING;
                } else {
                    return Status::PROCESSING;
                }
            }
        } catch (\Throwable $exception) {
            return Status::UNKNOWN;
        }

        return Status::COMPLETED;
    }
}