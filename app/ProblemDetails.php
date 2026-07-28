<?php

declare(strict_types=1);

namespace BIomie;

final class ProblemDetails
{
    public static function create(string $type, string $title, int $status, string $detail, string $instance): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
        ];
    }
}
