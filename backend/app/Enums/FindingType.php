<?php

namespace App\Enums;

enum FindingType: string
{
    case SAST = 'sast';
    case SCA  = 'sca';
}