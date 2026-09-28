<?php

namespace App\Enum;

enum DocumentStatus: string
{
    /** The template is being merged by ONLYOFFICE (worker). */
    case Merging = 'merging';
    /** Merged: can be opened, edited, downloaded. */
    case Ready = 'ready';
    /** The merge failed: see the error. */
    case Failed = 'failed';
}
