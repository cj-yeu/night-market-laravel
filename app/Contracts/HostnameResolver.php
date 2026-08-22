<?php

namespace App\Contracts;

interface HostnameResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
