<?php
/**
 * FormWizardBehavior Class file
 *
 * @author    Dmitriy Bushin
 * @copyright Copyright &copy; 2017 GrigTeam - All Rights Reserved
 * @license   BSD 3-Clause
 * @package   FormWizard
 */

namespace creditanalytics\formwizard\events;

/**
 * StepEvent class.
 * Represents events raised while processing wizard steps.
 */
class NextStepEvent extends FormWizardEvent
{
    public $params = [];
}
