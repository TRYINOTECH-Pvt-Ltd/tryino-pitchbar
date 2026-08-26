<?php

namespace App\Services\Vector\Exceptions;

class CircuitOpenException extends \RuntimeException
{
    public function __construct(string $client)
    {
        parent::__construct("Vector client circuit is open for {$client}; declining the call.");
    }
}
