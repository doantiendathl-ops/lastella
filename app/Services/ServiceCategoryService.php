<?php

namespace App\Services;

use App\Repositories\Eloquent\ServiceCategoryRepository;

class ServiceCategoryService extends CrudService
{
    public function __construct(ServiceCategoryRepository $repository)
    {
        parent::__construct($repository);
    }
}
