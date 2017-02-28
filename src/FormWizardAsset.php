<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace creditanalytics\formwizard;

use yii\web\AssetBundle;

/**
 * @author Dmitriy Bushin <dima.bushin@gmail.com>
 * @since 0.1.0
 */
class FormWizardAsset extends AssetBundle
{
    public $sourcePath = '@creditanalytics/formwizard/assets';
    public $js = [
        'js/pjaxEnActiveForm.js',
    ];
}
