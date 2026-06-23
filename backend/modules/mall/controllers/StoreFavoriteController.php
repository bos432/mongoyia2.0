<?php

namespace backend\modules\mall\controllers;

use common\models\mall\StoreFavorite;

class StoreFavoriteController extends BaseController
{
    public const VERSION = 'MONGOYIA_STORE_FAVORITE_PHASE14_BACKEND_V1';

    public $modelClass = StoreFavorite::class;

    protected $likeAttributes = ['name'];

    protected $editAjaxFields = [];

    protected $exportFields = [
        'id' => 'text',
        'store_id' => 'text',
        'user_id' => 'text',
        'name' => 'text',
        'status' => 'select',
        'created_at' => 'datetime',
    ];
}
