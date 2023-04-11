<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../interactive/behaviour.php');

class qbehaviour_appstester extends question_behaviour_with_multiple_tries {
    private const TRY_AGAIN_VISIBLE = 0x10;
    private const TRY_AGAIN_VISIBLE_READONLY = 0x11;

    public function is_compatible_question(question_definition $question): bool {
        return $question instanceof qtype_appstester_question;
    }

    public function can_finish_during_attempt(): bool {
        return true;
    }

    public function is_in_try_again_state() {
        $laststep = $this->qa->get_last_step();
        return $this->qa->get_state()->is_active() && $laststep->has_behaviour_var('submit');
    }

    public function adjust_display_options(question_display_options $options) {
        $_question = $this->qa->get_question();
        $_state = $this->qa->get_state();
        if (($_state->is_active() && $_question->hideresult_whileactive)
            || ($_state->is_finished() && $_question->hideresult_afterfinish)) {
            $options->marks = question_display_options::MAX_ONLY;
        } else {
            $options->marks = question_display_options::MARK_AND_MAX;
        }

        $save = clone($options);
        parent::adjust_display_options($options);

        if (!$this->is_in_try_again_state()) {
            return;
        }

        $options->readonly = $options->readonly ? self::TRY_AGAIN_VISIBLE_READONLY : self::TRY_AGAIN_VISIBLE;

        $options->feedback = $save->feedback;
        $options->numpartscorrect = $save->numpartscorrect;
    }

    public function get_expected_data() {
        if ($this->is_in_try_again_state()) {
            return array(
                'tryagain' => PARAM_BOOL,
            );
        } else if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    protected function is_same_response(question_attempt_step $pendingstep) {
        return $this->question->is_same_response(
            $this->qa->get_last_step_with_behaviour_var('result')->get_qt_data(),
            $pendingstep->get_qt_data()
        );
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();

        if ($state === question_state::$invalid) {
            if ($this->qa->get_last_step()->has_behaviour_var('status')) {
                return get_string('checking', 'qbehaviour_appstester');
            }

            return get_string('in_queue', 'qbehaviour_appstester');
        } else if ($state === question_state::$complete) {
            return get_string('checked', 'qbehaviour_appstester');
        } else if ($state === question_state::$finished) {
            if ($this->qa->get_last_step_with_behaviour_var('submit') === $this->qa->get_last_step_with_behaviour_var('status')) {
                return get_string('checking', 'qbehaviour_appstester');
            }
            return get_string('in_queue', 'qbehaviour_appstester');
        }
        if ($this->question->hideresult_afterfinish) {
            $showcorrectness = false;
        }
        return parent::get_state_string($showcorrectness);
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        }
        if ($this->is_in_try_again_state()) {
            if ($pendingstep->has_behaviour_var('tryagain')) {
                return $this->process_try_again($pendingstep);
            } else {
                return question_attempt::DISCARD;
            }
        } else {
            if ($pendingstep->has_behaviour_var('comment')) {
                return $this->process_comment($pendingstep);
            } else if ($pendingstep->has_behaviour_var('submit')) {
                return $this->process_submit($pendingstep);
            } else {
                return $this->process_save($pendingstep);
            }
        }
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return $this->summarise_finish($step);
        } else if ($step->has_behaviour_var('submit')) {
            return $this->summarise_submit($step);
        } else {
            return $this->summarise_save($step);
        }
    }

    public function process_try_again(question_attempt_pending_step $pendingstep) {
        $pendingstep->set_state(question_state::$todo);
        return question_attempt::KEEP;
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }
        if (!$this->is_complete_response($pendingstep)) {
            return question_attempt::DISCARD;
        }
//        if ($this->is_same_response($pendingstep)) {
//            return question_attempt::DISCARD;
//        }
        $pendingstep->set_state(question_state::$invalid);
        $pendingstep->set_fraction(0);
        $response = $pendingstep->get_qt_data();
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));

        return question_attempt::KEEP;
    }

    protected function adjust_fraction($fraction, question_attempt_pending_step $pendingstep) {
        return $fraction;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            $max_fraction = 0;

            $step_iterator = $this->qa->get_step_iterator();
            while ($step_iterator->valid()) {
                $max_fraction = max($max_fraction, $step_iterator->current()->get_fraction());
                $step_iterator->next();
            }

            $pendingstep->set_fraction($max_fraction);

            $laststep = $this->qa->get_last_step();
            if ($laststep->get_state() === question_state::$invalid) {
                $pendingstep->set_state(question_state::$finished);
            } else {
                if ($max_fraction < 0.000001) {
                    $pendingstep->set_state(question_state::$gradedwrong);
                } else if ($max_fraction > 0.999999) {
                    $pendingstep->set_state(question_state::$gradedright);
                } else {
                    $pendingstep->set_state(question_state::$gradedpartial);
                }
            }
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    public function process_save(question_attempt_pending_step $pendingstep) {
        /*
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }
        $status = question_attempt::KEEP;
        $pendingstep->set_state(question_state::$todo);
        return $status;
        */
        return question_attempt::DISCARD;
    }
}
