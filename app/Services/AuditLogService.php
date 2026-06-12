<?php

namespace App\Services;

use App\Repositories\Eloquent\AuditLogRepository;

class AuditLogService extends CrudService
{
    public function __construct(AuditLogRepository $repository)
    {
        parent::__construct($repository);
    }
}
