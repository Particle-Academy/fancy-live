<?php

declare(strict_types=1);

namespace FancyLive;

interface Transport
{
    /** Send exactly once, with no redirect or retry and no body re-encoding. */
    public function send(SignedRequest $request): HttpResponse;
}
