<?php

declare(strict_types=1);

namespace App\Service;

interface PushSender
{
    /** @return array{success:bool,expired:bool,reason:string} */
    public function send(array $subscription, string $payload, array $options = []): array;
}
