<?php

namespace App\Services;

use App\Repositories\Eloquent\FloorRepository;

class FloorService extends CrudService
{
    public function __construct(FloorRepository $repository)
    {
        parent::__construct($repository);
    }
}
