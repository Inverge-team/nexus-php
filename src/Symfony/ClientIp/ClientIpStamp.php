<?php

declare(strict_types=1);

namespace Inverge\Nexus\Symfony\ClientIp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Carries the end user's IP across a Messenger transport — see {@see \Inverge\Nexus\Support\ClientIp}. */
final class ClientIpStamp implements StampInterface
{
    public function __construct(public readonly ?string $ip)
    {
    }
}
