<?php

declare(strict_types=1);

namespace FancyLive;

use RuntimeException;

final class LiveFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null)
    {
        parent::__construct('FancyLive refused: '.$reason.'.');
    }
}
