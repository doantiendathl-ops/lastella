<?php

namespace App\Services;

use App\Repositories\Eloquent\ProductServiceCategoryRepository;

class ProductServiceCategoryService extends CrudService
{
    public function __construct(ProductServiceCategoryRepository $repository)
    {
        parent::__construct($repository);
    }
}
