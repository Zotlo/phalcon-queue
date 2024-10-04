<?php

namespace Phalcon\Queue\Jobs;

enum Status: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case FAILED = 'failed';
    case COMPLETED = 'completed';
    case UNKNOWN = 'unknown';
}