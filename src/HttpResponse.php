<?php

declare(strict_types=1);

namespace FancyLive;

/** No response body is retained or exposed by the publisher. */
final readonly class HttpResponse
{
    public function __construct(public int $status) {}
}
