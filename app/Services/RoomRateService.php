<?php

namespace App\Services;

use App\Repositories\Eloquent\RoomRateRepository;

class RoomRateService extends CrudService
{
    public function __construct(RoomRateRepository $repository)
    {
        parent::__construct($repository);
    }
}
