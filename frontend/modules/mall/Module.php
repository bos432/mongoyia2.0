<?php

namespace frontend\modules\mall;

/**
 * mall module definition class
 */
class Module extends \yii\base\Module
{
    /**
     * {@inheritdoc}
     */
    public $controllerNamespace = 'frontend\modules\mall\controllers';

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        if (empty($this->id)) {
            $this->id = 'mall';
        }

        // custom initialization code goes here
    }

    public function getUniqueId()
    {
        // 如果是根模块（即 Application），直接返回 id
        if ($this->module === null || $this->module instanceof \yii\web\Application) {
            return $this->id;
        }
        return parent::getUniqueId();
    }
}
