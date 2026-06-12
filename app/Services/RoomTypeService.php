<?php

namespace App\Services;

use App\Repositories\Eloquent\RoomTypeRepository;

class RoomTypeService extends CrudService
{
    public function __construct(RoomTypeRepository $repository)
    {
        parent::__construct($repository);
    }
}
