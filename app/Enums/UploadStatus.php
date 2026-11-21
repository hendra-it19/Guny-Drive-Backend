<?php

namespace App\Enums;

enum UploadStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Expired = 'expired';
    case Failed = 'failed';
}
