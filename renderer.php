<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../interactive/renderer.php');

class qbehaviour_appstester_renderer extends qbehaviour_renderer {
    public function controls(question_attempt $qa, question_display_options $options) {
        if ($options->readonly === qbehaviour_interactive::TRY_AGAIN_VISIBLE ||
                $options->readonly === qbehaviour_interactive::TRY_AGAIN_VISIBLE_READONLY) {
            return '';
        }
        return $this->submit_button($qa, $options);
    }

    public function feedback(question_attempt $qa, question_display_options $options) {
        if (!$qa->get_state()->is_active() || $qa->get_state() === question_state::$invalid ||
                ($options->readonly !== qbehaviour_interactive::TRY_AGAIN_VISIBLE &&
                $options->readonly !== qbehaviour_interactive::TRY_AGAIN_VISIBLE_READONLY)) {
            return '';
        }

        $attributes = array(
            'type' => 'submit',
            'id' => $qa->get_behaviour_field_name('tryagain'),
            'name' => $qa->get_behaviour_field_name('tryagain'),
            'value' => get_string('tryagain', 'qbehaviour_interactive'),
            'class' => 'submit btn btn-secondary',
        );
        if ($options->readonly === qbehaviour_interactive::TRY_AGAIN_VISIBLE_READONLY) {
            // This means the question really was rendered with read-only option.
            $attributes['disabled'] = 'disabled';
        }
        $output = html_writer::empty_tag('input', $attributes);
        if (empty($attributes['disabled'])) {
            $this->page->requires->js_init_call('M.core_question_engine.init_submit_button',
                array($attributes['id']));
        }
        return $output;
    }
}
