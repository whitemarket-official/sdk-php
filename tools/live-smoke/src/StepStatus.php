<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

enum StepStatus: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
}
