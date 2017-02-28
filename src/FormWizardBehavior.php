<?php
/**
 * FormWizardBehavior Class file
 *
 * @author    Dmitriy Bushin
 * @copyright Copyright &copy; 2017 GrigTeam - All Rights Reserved
 * @license   BSD 3-Clause
 * @package   FormWizard
 */

namespace creditanalytics\formwizard;

use Yii;
use yii\helpers\Url;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;
use creditanalytics\formwizard\events\FormWizardEvent;
use creditanalytics\formwizard\events\CurrentStepEvent;
use creditanalytics\formwizard\events\NextStepEvent;

/**
 * FormWizardBehavior Class
 *
 * Processes steps making multple form handling simple to implement.
 *
 * The Behavior raises a number of events to indicate progress:
 * beforeWizard - raised before the wizard starts
 * wizardStep - raised when a step is processed. If the event handler sets
 * Event::handled = TRUE the behavior saves Event::data and the wizard moves on
 * to the next step, otherwise the behavior returns Event::data - typically a
 * rendering result.
 * afterwizard  - raised when the wizard has ended. It is possible that the
 * wizard has not processed all steps
 *
 * Two events are used to indicate error conditions:
 * invalidStep - raised if the step being requested is invalid
 * stepExpired - raised if a step has taken too long to process
 * @see $timeout
 */
class FormWizardBehavior extends Behavior
{
    const BRANCH_DESELECT = 0;
    const BRANCH_SELECT   = 1;
    const BRANCH_SKIP     = -1;

    const EVENT_AFTER_FORM_WIZARD  = 'afterFormWizard';
    const EVENT_BEFORE_FORM_WIZARD = 'beforeFormWizard';
    const EVENT_CURRENT_STEP       = 'currentStep';
    const EVENT_NEXT_STEP          = 'nextStep';
    const EVENT_INVALID_STEP       = 'invalidStep';
    const EVENT_STEP_EXPIRED       = 'stepExpired';

    const DIRECTION_BACKWARD = -1;
    const DIRECTION_REPEAT   = 0;
    const DIRECTION_FORWARD  = 1;

    const HTTP_STATUS_CODE = 302;

    public $usePjax = false; // TODO delete <------------

    /**
     * @var boolean If TRUE, the behavior will redirect to the "expected step"
     * after a step has been successfully completed. If FALSE, it will redirect
     * to the next step in the steps array.
     *
     * The difference between the "expected step" and the "next step" is when the
     * user goes to a previous step in the wizard; the expected step is the first
     * unprocessed step, the next step is the next step. For example, if the
     * wizard has 5 steps and the user has completed four of them and then goes
     * back to the second step; the expected step is the fifth step, the next
     * step is the third step.
     *
     * If {@link $forwardOnly === TRUE} the expected step is the next step
     */
    public $autoAdvance = true;
    /**
     * @var boolean If TRUE the first "non-skipped" branch in a group will be
     * used if a branch has not been specifically selected.
     */
    public $defaultBranch = true;
    /**
     * @var boolean If TRUE previously completed steps can not be reprocesed.
     */
    public $forwardOnly = false;
    /**
     * @var array Event handlers; event names are the keys and the values are
     * the event handler
     * Events are:
     * - beforeWizard - raised before the wizard runs
     * - afterWizard  - raised after the wizard has finished
     * - processStep  - raised when a step is being processed
     * - stepExpired  - raised if a step timeout has expired
     * - invalidStep  - raised if the step is an invalid step
     */
    public $events = [];
    /**
     * @var string Query parameter for the step. This must match the name of the
     * parameter in the action that calls the wizard.
     */
    public $queryParam = 'step';
    /**
     * @var string The session key for the wizard.
     */
    public $sessionKey = 'FormWizard';
    /**
     * @var integer The timeout in seconds. Set to empty for no timeout.
     * If a step is not completed within the timeout period the wizard expires.
     */
    public $timeout;
    /**
     * @var string The session key that holds branch directives.
     */
    private $_branchKey;
    /**
     * @var string The session key that indexes the number of step repetitions.
     */
    private $_indexRepetitionKey;
    /**
     * @var array List of steps, in order, that are to be included in the wizard.
     * basic example: ['login_info', 'profile', 'confirm']
     *
     * Steps can be labled: ['Username and Password' => 'login_info', 'User Profile' => 'profile', 'confirm']
     *
     * The steps array can also contain branch groups that are used to determine
     * the path at runtime. A branch group is a named step where the value is a
     * steps array which may itself contain branch groups.
     * plot-branched example: ['job_application', ['degree' => ['college', 'degree_type'], 'nodegree' => 'experience'], 'confirm'];
     *
     * The branch names (i.e. 'degree', 'nodegree') are arbitrary.
     *
     * The first "non-skipped" branch in a group (see branch()) is used by
     * default if $defaultBranch == true and a branch has not been specifically
     * selected.
     */
    private $_stepsConfig = [];
    /**
     * @var string The session key that holds data for processed steps.
     */
    private $_modelsKey;
    /**
     * @var string The session key that holds parsed steps.
     */
    private $_stepsKey;
    /**
     * @var string The session key that holds the timeout value.
     */
    private $_timeoutKey;
    /**
     * @var yii\web\Session The session
     */
    private $_session;

    /**
     * Attaches this behavior to the owner.
     *
     * @param yii\base\Controller $owner The controller that this behavior is to
     * be attached to.
     */
    public function attach($owner)
    {
        if (!$owner instanceof \yii\base\Controller) {
            throw new InvalidConfigException('Owner must be an instance of yii\base\Controller');
        }

        parent::attach($owner);

        // Attach WizardBehavior events to the owner
        foreach ($this->events as $name => $handler) {
            $owner->on($name, $handler);
        }

        $this->_session            = Yii::$app->getSession();
        $this->_branchKey          = $this->sessionKey.'.branches';
        $this->_indexRepetitionKey = $this->sessionKey.'.indexRepetition';
        $this->_modelsKey          = $this->sessionKey.'.models';
        $this->_stepsKey           = $this->sessionKey.'.steps';
        $this->_timeoutKey         = $this->sessionKey.'.timeout';
    }

    /**
     * Start the wizard.
     * Raises the `beforeWizard` event. To prevent the wizard from running the
     * event handler may set the event's continue property to FALSE
     *
     * @return boolean Whether the wizard started
     */
    protected function start()
    {
        if ($this->beforeFormWizard()) {
            $this->_session[$this->_branchKey]          = new \ArrayObject;
            $this->_session[$this->_indexRepetitionKey] = 0;
            $this->_session[$this->_modelsKey]          = new \ArrayObject;

            $this->parseSteps();
            return true;
        }

        return false;
    }

    /**
     * End the wizard.
     * Raises the `afterWizard` event and by default deletes wizard data in the
     * session. To preserve the data the event handler can set the event's
     * continue property to FALSE
     *
     * @param boolean|string boolean: FALSE - the wizard did not start,
     * TRUE - the wizrd completed; string: The last step processed
     * @return mixed The event data
     */
    protected function finish($step)
    {
        $event = new FormWizardEvent([
            'sender'    => $this,
            'step'      => $step,
            'models'    => (empty($step) ? null : $this->readModels())
        ]);
        $this->owner->trigger(self::EVENT_AFTER_FORM_WIZARD, $event);

        if ($event->formWizardContinue) {
            $this->resetFormWizard();
        }

        return $event->html;
    }

    /**
     * Process the given step.
     * This method is called for each step from the controller action using the
     * wizard
     *
     * If $step === NULL and the wizard has not started the wizard raises a
     * `beforeWizard` event and starts the wizard; if the wizard has completed
     * an `afterWizard` event is raised and the wizard ends.
     *
     * Otherwise the wizard moves to the next step and raises a `wizardStep` event.
     * The event handler processes the step and sets StepEvent::data,
     * StepEvent::nextStep, StepEvent::branch, StepEvent::continue, and
     * StepEvent::handled to determine the behaviour of WizardBehavior.
     *
     * If StepEvent::continue === TRUE:
     * If StepEvent::handled === TRUE the data in StepEvent::data is saved, any
     * branches taken into account, and the wizard moves to the next step; what
     * the next step is is determined by StepEvent::nextStep.
     * If StepEvent::handled === FALSE the data in StepEvent::data is returned.
     *
     * If StepEvent::continue === FALSE:
     * If StepEvent::handled === TRUE the data in StepEvent::data is saved and
     * the wizard ends by raising an `afterWizard` event with WizardEvent::step
     * set to the current step.
     * If StepEvent::handled === FALSE the wizard ends by raising an
     * `afterWizard` event with WizardEvent::step set to NULL.
     *
     * @param string $step Name of the step to be processed.
     */
    public function proccessStep($step = null)
    {
        // $this->_session[$this->_indexRepetitionKey] = 0;
        // print_r('count -----> code 260 ');
        // print_r( count($this->_session[$this->_modelsKey]) );

        if (null === $step) {
            if (!$this->hasStarted() && !$this->start()) {
                return $this->finish(false);
            } elseif ($this->hasCompleted()) {
                return $this->finish(true);
            } else {
                $this->moveNext();
            }

        // NOTE [[step]] is not valid form wizard step
        } elseif(!$this->isValidStep($step)) {
            $event = new FormWizardEvent(['sender' => $this, 'step' => $step]);
            $this->owner->trigger(self::EVENT_INVALID_STEP, $event);

            if ($event->formWizardContinue) {$this->moveNext();}

            $this->resetFormWizard();
            return $event->html;

        // NOTE If [[step]] is a valid step we proccess it
        } elseif($this->isValidStep($step)) {
            // Raise a processStep event
            $event = new CurrentStepEvent([
                'n'      => $this->_session[$this->_indexRepetitionKey],
                'sender' => $this,
                'step'   => $step,
                't'      => (isset($this->_session[$this->_modelsKey][$step])
                    ? count($this->_session[$this->_modelsKey][$step])
                    : 0
                ),
                'route' => Url::to(array_merge([''], [$this->queryParam => $step])),
            ]);

            // For repetiotions
            $indexRepetition = $this->_session[$this->_indexRepetitionKey];
            $models          = $this->_session[$this->_modelsKey];
            $event->model    = (isset($models[$step][$indexRepetition])) ? $models[$step][$indexRepetition] : null;

            // NOTE Init Form Wizard Event for current step and wait
            // all handlers have worked ----> curentStepHandler();
            $this->owner->trigger(self::EVENT_CURRENT_STEP, $event);

            if (!$event->formWizardContinue) {
                if (!$event->handled) {
                    $step = null;
                } else {
                    $this->saveStep($step, $event->model);
                }
                return $this->finish($step);

            }

            if ($event->formWizardContinue) {
                if (!$event->handled) {
                    return $event->html;
                } else {
                    // if is needed to run previous step
                    if ($event->nextStep !== self::DIRECTION_BACKWARD) {
                        $this->saveStep($step, $event->model);
                        if (!empty($event->branches)) {$this->branch($event->branches);}
                    }

                    if (!$this->hasStepExpired() || $this->stepExpired($step)) {
                        return $this->moveNext($step, $event->nextStep);
                    } else {
                        return $this->finish($step);
                    }
                }
            }
        } // endif $this->isValidStep($step)
    }

    /**
     * Sets data into wizard session. Particularly useful if the data
     * originated from WizardBehavior::read() as this will restore a previous
     * session. $data[0] is the step data, $data[1] the branch data, $data[2]
     * is the timeout value.
     *
     * @param array Data to be written to the wizard session.
     * @return boolean TRUE if the data was successfully restored, FALSE if not
     */
    public function restoreFormWizard($data)
    {
        if (sizeof($data) !== 3 || !is_array($data[0]) || !is_array($data[1]) || !(is_integer($data[2]) || is_null($data[2]))) {
            return false;
        }
        $this->_session[$this->_modelsKey]  = $data[0];
        $this->_session[$this->_branchKey]  = $data[1];
        $this->_session[$this->_timeoutKey] = $data[2];
        return true;
    }

    /**
     * Saves data into the Session.
     * The `processStep` event handler should call this method to save step data.
     *
     * @param string Name of the step to save
     * @param mixed Data to be saved
     */
    protected function saveStep($step, $data)
    {
        // $models = $this->_session[$this->_modelsKey];
        // if (!isset($models[$step])) {
            // $this->_session[$this->_modelsKey][$step] = new \ArrayObject;
        // }
        // $this->_session[$this->_modelsKey][$step][] = $data;

        $this->_session[$this->_modelsKey][$step] = $data;
    }

    /**
     * Reads data stored for a step.
     *
     * @param string The name of the step. If empty the data for all steps are
     * returned.
     * @return mixed Data for the specified step; array: data for all steps;
     * null is no data exist for the specified step.
     */
    public function readStepsModel($step = '')
    {
        return (empty($step)
            ? $this->_session[$this->_modelsKey]
            : (isset($this->_session[$this->_modelsKey][$step])
                ? $this->_session[$this->_modelsKey][$step]
                : []
            )
        );
    }

    /**
     * Returns the number of steps.
     * _Note_: that this is for the current steps; branching may vary the number
     * of steps
     */
    public function getStepCount()
    {
        return count($this->_session[$this->_stepsKey]);
    }

    /**
     * @param array Steps configuration.
     */
    public function setSteps($steps)
    {
        $this->_stepsConfig = $steps;
    }

    /**
     * @return array Steps being processed.
     */
    public function getSteps()
    {
        return array_keys($this->_session[$this->_stepsKey]);
    }

    /**
     * Returns the label for the given step
     *
     * @param string $step Step name.
     * @return string Label for the given step
     */
    public function stepLabel($step)
    {
        return $this->_session[$this->_stepsKey][$step];
    }

    /**
     * Resets the wizard by deleting the wizard session variables.
     */
    public function resetFormWizard() {
        $sessionKeys = [
            '_branchKey',
            '_indexRepetitionKey',
            '_modelsKey',
            '_stepsKey',
            '_timeoutKey'
        ];
        foreach ($sessionKeys as $_key) {
            $this->_session->remove($this->$_key);
        }
    }

    /**
     * Returns a value indicating if the step has expired
     *
     * @return boolean TRUE if the step has expired, FALSE if not
     */
    protected function hasStepExpired()
    {
        return isset($this->_session[$this->_timeoutKey]) &&
            $this->_session[$this->_timeoutKey] < time();
    }

    /**
     * Moves the wizard to the next step.
     * What the next step is is determined by StepEvent::nextStep, valid values
     * are:
     * - WizardBehavior::DIRECTION_FORWARD (default) - moves to the next step.
     * If autoAdvance == TRUE this will be the expectedStep,
     * if autoAdvance == FALSE this will be the next step in the steps array
     * - WizardBehavior::DIRECTION_BACKWARD - moves to the previous step (which
     * may be an earlier repeated step). If WizardBehavior::forwardOnly === TRUE
     * this results in an invalid step
     * - WizardBehavior::DIRECTION_REPEAT - repeats the current step to get
     * another set of data
     *
     * If a string it is the name of the step to return to. This allows multiple
     * steps to be repeated. If WizardBehavior::forwardOnly === TRUE this
     * results in an invalid step.
     *
     * @param StepEvent $event The current step event. If NULL the wizard goes to
     * the first step
     */
    protected function moveNext($currentStep = null, $nextStep = null)
    {
        // print_r('count -----> code 476 ');
        // print_r($this->_session[$this->_indexRepetitionKey]);
        // first step, resumed wizard, or continuing after an invalid step
        if (null === $nextStep) {
            $steps  = array_keys($this->_session[$this->_stepsKey]);
            $models = $this->_session[$this->_modelsKey];
            $this->_session[$this->_indexRepetitionKey] = 0;
            $nextStep = (count($models) && $this->autoAdvance) ? $this->expectedNextStep($currentStep, self::DIRECTION_FORWARD) : array_shift($steps);

        } elseif (is_string($nextStep)) {
            if ($this->autoAdvance) {
                throw new \yii\base\InvalidConfigException('StepEvent::nextStep cannot be a string if FormWizardBehavior::autoAdvance is TRUE');
            }
            if (!$this->isValidStep($nextStep)) {
                throw new \yii\base\InvalidConfigException('StepEvent::nextStep must be valid step string');
            }
            $this->_session[$this->_indexRepetitionKey] = count(
                $this->_session[$this->_modelsKey][$nextStep]
            );
            print_r('count -------> code:488 ');
            print_r($this->_session[$this->_indexRepetitionKey]);

        } elseif (self::DIRECTION_REPEAT === $nextStep) {
            $this->_session[$this->_indexRepetitionKey] += 1;

        } elseif (self::DIRECTION_BACKWARD === $nextStep) {
            if ($this->_session[$this->_indexRepetitionKey] > 0) {
                // there are earlier repeated steps
                $this->_session[$this->_indexRepetitionKey] -= 1;
            } else {
                // go to the previous step
                $steps = array_keys($this->_session[$this->_stepsKey]);
                $index = array_search($currentStep, $steps);
                $previousIndex = ($index === 0) ? 0 : ($index - 1);

                $nextStep = $steps[$previousIndex];
                $this->_session[$this->_indexRepetitionKey] = count(
                    $this->_session[$this->_modelsKey][$nextStep]
                ) - 1;

                print_r('count -------> code:509 ');
                print_r($this->_session[$this->_indexRepetitionKey]);

            }

        } elseif (self::DIRECTION_FORWARD === $nextStep) {
            if ($this->autoAdvance) {
                $nextStep = $this->expectedNextStep($currentStep, self::DIRECTION_FORWARD);
                $this->_session[$this->_indexRepetitionKey] = 0;
            } else {
                $stepKeys = $this->_session[$this->_stepsKey];
                $steps    = array_keys($stepKeys);
                $models   = $this->_session[$this->_modelsKey];
                $index    = array_search($nextStep, $steps) + 1;
                $nextStep = ($index === count($stepKeys)) ? null : $steps[$index];
                $this->_session[$this->_indexRepetitionKey] = (isset($models[$nextStep])
                    ? count($models[$nextStep])
                    : 0
                );
                print_r('count -------> code:528 ');
                print_r($this->_session[$this->_indexRepetitionKey]);
            }
        }

        $params = $this->owner->actionParams;

        if (is_null($nextStep)) { // wizard has finished
            unset($params[$this->queryParam]);
        } else {
            $params[$this->queryParam] = $nextStep;
        }

        if ($this->timeout) {
            $this->_session[$this->_timeoutKey] = time() + $this->timeout;
        }

        $event = new NextStepEvent([
            'sender' => $this,
            'step' => $nextStep,
            'route' => Url::to(array_merge([''], $params)),
        ]);

        $this->owner->trigger(self::EVENT_NEXT_STEP, $event);
        return $event->html;
    }

    /**
     * Returns a value indicating if the wizard has started
     *
     * @return boolean TRUE if the wizard has started, FALSE if not
     */
    protected function hasStarted()
    {
        return isset($this->_session[$this->_modelsKey]);
    }

    /**
     * Returns a value indicating if the wizard has completed
     *
     * @return boolean TRUE if the wizard has completed, FALSE if not
     */
    protected function hasCompleted() {
        return !(bool)$this->expectedStep();
    }

    /**
     * Selects, skips, or deselects branch(es)
     *
     * @param array|string Branch directives.
     * array: either an array of branch names to select or
     * an array of "branch name" => branchDirective pairs
     * branchDirective = [WizardBehavior::BRANCH_SELECT|WizardBehavior::BRANCH_SKIP|WizardBehavior::BRANCH_DESELECT|]
     * string: The branch name or a list of branch names to select
     */
    protected function branch($directives)
    {
        if (is_string($directives)) {
            $directives = explode(',', $directives);
            foreach ($directives as &$name) {
                $name = trim($name);
            }
        }

        foreach ($directives as $name => $directive) {
            if ($directive === self::BRANCH_DESELECT
                && isset($this->_session[$this->_branchKey][$name])
            ) {
                unset($this->_session[$this->_branchKey][$name]);
            } else {
                if (is_int($name)) {
                    $name = $directive;
                    $directive = self::BRANCH_SELECT;
                }
                $this->_session[$this->_branchKey][$name] = $directive;
            }
        }

        $this->parseSteps();
    }

    /**
     * Validates the $step in two ways:
     * 1. Validates that the step exists in $this->_session[$this->_stepsKey] array.
     * 2. Validates that the step is the expected step or,
     *    if self::forwardsOnly === FALSE, before it.
     *
     * @param string Step to validate.
     * @return boolean Whether the step is valid; TRUE if the step is valid,
     * FALSE if not
     */
    protected function isValidStep($step)
    {
        if (!$this->hasStarted()) {
            return false;
        }

        // $steps = array_keys($this->_session[$this->_stepsKey]);
        // $index = array_search($step, $steps);
        // $expectedStep = $this->expectedStep(); // NULL if wizard finished

        // if ($index == 0 || ($index >= 0 && ($this->forwardOnly
        //     ? $expectedStep !== null &&
        //         $index === array_search($expectedStep, $steps)
        //     : $expectedStep === null ||
        //         $index <= array_search($expectedStep, $steps)
        // )) || $this->hasCompleted()) {
        //     return true;
        // }
        // return false;

        return in_array($step, array_keys($this->_session[$this->_stepsKey]));
    }

    /**
     * Returns the first unprocessed step (i.e. step data not saved in Session).
     *
     * @return string|null The first unprocessed step; NULL if all steps have
     * been processed
     */
    protected function expectedStep()
    {
        $steps  = $this->_session[$this->_stepsKey];
        $models = $this->_session[$this->_modelsKey];
        foreach (array_keys($steps) as $step) {
            if (!isset($models[$step])) {
                return $step;
            }
        }
    }

    /**
     * Returns the first unprocessed step (i.e. step data not saved in Session).
     *
     * @return string|null The first unprocessed step; NULL if all steps have
     * been processed
     */
    protected function expectedNextStep($currentStep, $direction)
    {
        $steps = array_keys($this->_session[$this->_stepsKey]);
        $index = array_search($currentStep, $steps);

        $nextStepIndex = (($index + $direction) < 0) ? 0 : ($index + $direction);
        $nextStepIndex = (($index + $direction + 1) > count($steps) ) ? (count($steps) - 1) : ($index + $direction);

        return $steps[$nextStepIndex];
    }

    /**
     * Parse the steps into a flat array and get their labels
     */
    protected function parseSteps()
    {
        $this->_session[$this->_stepsKey] = $this->_parseSteps($this->_stepsConfig);
    }

    /**
     * Parses the steps array into a "flat" array by resolving branches.
     * Branches are resolved according the settings
     *
     * @param array The steps array.
     * @return array Steps to take
     */
    private function _parseSteps($steps)
    {
        $parsed = [];

        foreach ($steps as $label => $step) {
            $branch = '';

            if (is_array($step)) {
                foreach (array_keys($step) as $branchName) {
                    $branchDirective = (isset($this->_session[$this->_branchKey][$branchName])
                        ? $this->_session[$this->_branchKey][$branchName]
                        : self::BRANCH_DESELECT
                    );

                    if ($branchDirective === self::BRANCH_SELECT || (
                        empty($branch) &&
                        $this->defaultBranch &&
                        $branchDirective !== self::BRANCH_SKIP
                    )) {
                        $branch = $branchName;
                    }
                }

                if (!empty($branch)) {
                    if (is_array($step[$branch])) {
                        $parsed = array_merge(
                            $parsed, $this->_parseSteps($step[$branch])
                        );
                    } else {
                        $parsed[$label] = $step[$branch];
                    }
                }
            } else {
                $parsed[$step] = (is_string($label)
                    ? $label
                    : Inflector::titleize($step, true)
                );
            }
        }

        return $parsed;
    }

    /**
     * This method is invoked before the wizard runs.
     * The default implementation raises a `beforeWizard` event.
     * You may override this method to do preliminary checks before the wizard
     * runs. Make sure the parent implementation is invoked so that the event is
     * raised.
     *
     * @return boolean Whether the wizard should run; defaults to TRUE.
     * To prevent the wizard from running the event handler should set
     * Event::continue = FALSE.
     */
    protected function beforeFormWizard()
    {
        $event = new FormWizardEvent(['sender' => $this]);
        $this->owner->trigger(self::EVENT_BEFORE_FORM_WIZARD, $event);
        return $event->formWizardContinue;
    }

    /**
     * This method is invoked when a step has expired.
     * The default implementation raises an `expired` event.
     * If you override this method make sure the parent implementation is invoked
     * so that the event is raised.
     *
     * @param string $step The step to process
     * @return boolean Whether the wizard should continue; TRUE if the wizard
     * should continue, FALSE if not
     */
    protected function stepExpired($step)
    {
        $event = new FormWizardEvent([
            'sender'   => $this,
            'step'     => $step,
            'stepData' => $this->readStepsModel($step)
        ]);
        $this->owner->trigger(self::EVENT_STEP_EXPIRED, $event);
        $this->_session[$this->_modelsKey][$step] = $event->model;
        return $event->formWizardContinue;
    }

    /**
     * Pauses the wizard.
     * The wizard is reset and a serialized representation of the current state
     * returned.
     * _NOTE_: The application may wish to encrypt the returned value depending on
     * the data collected by the wizard
     *
     * @return string serialized representation of the current stata of the wizard
     */
    public function pauseFormWizard()
    {
        $sessionKeys = [
            '_branchKey',
            '_indexKey',
            '_stepDataKey',
            '_stepsKey',
            '_timeoutKey'
        ];
        $data = [];
        foreach ($sessionKeys as $_key) {
            $data[$this->$_key] = (isset($this->_session[$this->$_key])
                ? $this->_session[$this->$_key]
                : null
            );
        }

        $this->resetWizard();

        return serialize($data);
    }

    /**
     * Resumes the wizard
     *
     * @param string The serialized representation of the wizard
     */
    public function resumeFormWizard($wizard)
    {
        foreach (unserialize($wizard) as $key => $value) {
            if ($value !== null) {
                $this->_session[$key] = $value;
            }
        }
    }
}
