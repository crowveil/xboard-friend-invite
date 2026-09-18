<?php

namespace Plugin\FriendInvite\Services;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 422)
    {
        parent::__construct($reason);
    }
}
