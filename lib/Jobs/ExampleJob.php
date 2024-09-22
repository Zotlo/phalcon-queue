<?php

namespace Phalcon\Queue\Jobs;

class ExampleJob extends Job
{
    /** @var string */
    private string $uniqId;

    public function __construct(string $uniqId)
    {
        $this->uniqId = $uniqId;
    }

    public function handle(): void
    {
//        $this->db->query(
//            'INSERT INTO test SET payload = :payload', [
//                'payload' => json_encode([
//                    'id'        => uniqid(),
//                    'time'      => time(),
//                    'microtime' => microtime(true),
//                ])
//            ]
//        );

        $file = fopen('test.log', 'a+');
        fwrite($file, "JOB START: " . $this->uniqId . PHP_EOL);
        fclose($file);
    }
}