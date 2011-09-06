<?php

require_once $CFG->dirroot . '/blocks/cps/formslib.php';

abstract class crosslist_form extends cps_form {
    public static function next_from($next, $data, $courses) {
        return parent::next_from('crosslist', $next, $data, $courses);
    }

    public static function create($courses, $state = null, $extra = null) {
        return parent::create('crosslist', $courses, $state, $extra);
    }
}

class crosslist_form_select extends crosslist_form {
    var $current = self::SELECT;
    var $next = self::SHELLS;

    public static function build($courses) {
        return array('courses' => $courses);
    }

    function definition() {
        $m =& $this->_form;

        $courses = $this->_customdata['courses'];

        $semesters = array();

        $m->addElement('header', 'select_course', self::_s('crosslist_select'));

        $m->addElement('static', 'selected_label', '', '');
        foreach ($courses as $course) {
            foreach ($course->sections as $section) {
                $id = $section->semesterid;
                if (isset($semesters[$id])) {
                    continue;
                }

                $semesters[$id] = $section->semester();
            }

            $semester = $semesters[reset($course->sections)->semesterid];

            $display = "$semester->year $semester->name $course->department $course->cou_number";

            $m->addElement('checkbox', 'selected_' . $course->id, '', $display);
        }

        $this->generate_buttons();

        // Used later in validation
        $this->semesters = $semesters;
    }

    function validation($data) {
        $courses = $this->_customdata['courses'];

        $semesters = $this->semesters;

        $errors = array();

        // Must select two...
        // Must select from same semester
        $selected = 0;
        $selected_semester = null;
        foreach ($data as $key => $value) {
            $is_a_match = preg_match('/^selected_(\d+)/', $key, $matches);

            if ($is_a_match) {
                $selected ++;

                $courseid = $matches[1];

                $current_semester = reset($courses[$courseid]->sections)->semesterid;

                if (empty($selected_semester)) {
                    $selected_semester = $current_semester;
                }

                if ($selected_semester != $current_semester) {
                    $errors[$key] = self::_s('err_same_semester', $semesters[$selected_semester]);
                }
            }
        }

        if ($selected < 2) {
            $errors['selected_label'] = self::_s('err_not_enough');
        }

        return $errors;
    }
}

class crosslist_form_shells extends crosslist_form {
    var $current = self::SHELLS;
    var $next = self::DECIDE;
    var $prev = self::SELECT;

    public static function build($courses) {

        $semester = null;

        $selected_courses = array();
        foreach ($courses as $course) {
            $selected = optional_param('selected_' . $course->id, null, PARAM_INT);

            if (!$semester) {
                $semester = reset($course->sections)->semester();
            }

            if ($selected) {
                $selected_courses['selected_' . $course->id] = $course;
            }
        }

        return array('selected_courses' => $selected_courses, 'semester' => $semester);
    }

    function definition() {
        $m =& $this->_form;

        $courses = $this->_customdata['selected_courses'];

        $semester = $this->_customdata['semester'];

        $m->addElement('header', 'selected_courses', self::_s('crosslist_you_have'));

        $total = $last = 0;

        foreach ($courses as $selected => $course) {
            $display = "$semester->year $semester->name $course->department $course->cou_number";

            $m->addElement('static', 'course_' . $course->id, $display);

            $m->addElement('hidden', $selected, 1);

            $last = count($course->sections);
            $total += $last;
        }


        $number = min(floor($total / count($courses)), $total - $last);

        $range = range(1, $number);
        $options = array_combine($range, $range);

        $m->addElement('select', 'shells', self::_s('split_how_many'), $options);

        $this->generate_states_and_buttons();
    }
}

class crosslist_form_decide extends crosslist_form {
    var $current = self::DECIDE;
    var $next = self::CONFIRM;
    var $prev = self::SHELLS;

    public static function build($courses) {
        $shells = required_param('shells', PARAM_INT);

        return array('shells' => $shells) + crosslist_form_shells::build($courses);
    }

    function definition() {
        global $USER;

        $m =& $this->_form;

        $courses = $this->_customdata['selected_courses'];

        $semester = $this->_customdata['semester'];

        $to_coursenames = function($course) {
            return "$course->department $course->cou_number";
        };

        $course_names = implode (' / ', array_map($to_coursenames, $courses));

        $display = "$semester->year $semester->name $course_names";

        $m->addElement('header', 'selected_courses', $display);

        $before = array();
        foreach ($courses as $selected => $course) {
            foreach ($course->sections as $section) {
                $before[$section->id] = $to_coursenames($course) . " $section->sec_number";
            }

            $m->addElement('hidden', $selected, 1);
        }

        $shells = array();
        foreach (range(1, $this->_customdata['shells']) as $groupingid) {
            $updating = !empty($this->_customdata['shell_values_'.$groupingid]);

            if ($updating) {
                $shell_name_value = $this->_customdata['shell_name_'.$groupingid.'_hidden'];
                $shell_values = $this->_customdata['shell_values_'.$groupingid];

                $shell_ids = explode(',', $shell_values);
                $shell_sections = array_map(function($sec) use ( &$before) {
                    $section = $before[$sec];
                    unset($before[$sec]);
                    return $section;
                }, $shell_ids);

                $shell_options = array_combine($shell_ids, $shell_sections);
            } else {
                $shell_name_value = $course_names;
                $shell_values = '';

                $shell_options = array();
            }

            $shell_label =& $m->createElement('static', 'shell_' . $groupingid .
                '_label', '', $semester->year . ' ' . $semester->name .
                ' <span id="shell_name_'.$groupingid.'">'
                . $shell_name_value . '</span>');
            $shell =& $m->createElement('select', 'shell_'.$groupingid, '', $shell_options);
            $shell->setMultiple(true);

            $shell_name_params = array('style' => 'display: none;');
            $shell_name =& $m->createElement('text', 'shell_name_' . $groupingid,
                '', $shell_name_params);
            $shell_name->setValue($shell_name_value);

            $link = html_writer::link('shell_'.$groupingid, $this->_s('customize_name'));

            $radio_params = array('id' => 'selected_shell_'.$groupingid);
            $radio =& $m->createElement('radio', 'selected_shell', '', '', $groupingid, $radio_params);

            $radio->setChecked($groupingid == 1);

            $for = ' for ' . fullname($USER);

            $shells[] = $shell_label->toHtml() . $for . ' (' . $link . ')<br/>' .
                $shell_name->toHtml() . '<br/>' . $radio->toHtml() . $shell->toHtml();

            $m->addElement('hidden', 'shell_values_'.$groupingid, $shell_values);
            $m->addElement('hidden', 'shell_name_'.$groupingid.'_hidden', $shell_name_value);
        }

        $previous_label =& $m->createElement('static', 'available_sections',
            '', $this->_s('available_sections'));

        $previous =& $m->createElement('select', 'before', '', $before);
        $previous->setMultiple(true);

        $m->addElement('html', '<div id="split_error"></div>');

        $previous_html =& $m->createElement('html', '
            <div class="split_available_sections">
                '.$previous_label->toHtml().'<br/>
                '.$previous->toHtml().'
            </div>
        ');

        $move_left =& $m->createElement('button', 'move_left', self::_s('move_left'));
        $move_right =& $m->createElement('button', 'move_right', self::_s('move_right'));

        $button_html =& $m->createElement('html', '
            <div class="split_movers">
                '.$move_left->toHtml().'<br/>
                '.$move_right->toHtml().'
            </div>
        ');

        $shell_html =& $m->createElement('html', '
            <div class="split_bucket_sections">
                '. implode('<br/>', $shells) . '
            </div>
        ');

        $shifters = array($previous_html, $button_html, $shell_html);

        $m->addGroup($shifters, 'shifters', '', array(' '), true);

        $m->addElement('hidden', 'shells', '');

        $this->generate_states_and_buttons();
    }
}

class crosslist_form_confirm extends crosslist_form {
    var $current = self::CONFIRM;
    var $prev = self::DECIDE;
    var $next = self::FINISHED;

    public static function build($courses) {
        $data = crosslist_form_decide::build($courses);

        $extra = array();
        foreach (range(1, $data['shells']) as $number) {
            $namekey = 'shell_name_'.$number.'_hidden';
            $valuekey = 'shell_values_'.$number;

            $extra[$namekey] = required_param($namekey, PARAM_TEXT);
            $extra[$valuekey] = required_param($valuekey, PARAM_RAW);
        }

        return $extra + $data;
    }

    function definition() {
        $m =& $this->_form;

        $courses = $this->_customdata['selected_courses'];

        $semester = $this->_customdata['semester'];

        $sections = array_reduce($courses, function ($in, $course) {
            return $in + $course->sections;
        }, array());

        $to_coursenames = function ($course) {
            return "$course->department $course->cou_number";
        };

        $course_names = implode(' / ', array_map($to_coursenames, $courses));

        $display = "$semester->year $semester->name";

        $m->addElement('header', 'selected_courses', "$display $course_names");

        $m->addElement('static', 'chosen', self::_s('chosen'), '');

        foreach (range(1, $this->_customdata['shells']) as $number) {
            $namekey = 'shell_name_'.$number.'_hidden';
            $valuekey = 'shell_values_'.$number;

            $name = $this->_customdata[$namekey];

            $values = $this->_customdata[$valuekey];

            if (empty($values)) {
                continue;
            }

            $html = '<ul class="split_review_sections">';
            foreach (explode(',', $values) as $sectionid) {
                $section = $sections[$sectionid];
                $key = 'selected_'.$section->courseid;

                $course_name = $to_coursenames($courses[$key]);

                $html .= '<li>' . $course_name . ' ' . $section->sec_number . '</li>';
            }
            $html .= '</ul>';

            $m->addElement('static', 'shell_label_'.$number, $name, $html);

            $m->addElement('hidden', $namekey, $name);
            $m->addElement('hidden', $valuekey, $values);
        }

        $m->addElement('hidden', 'shells', $this->_customdata['shells']);

        foreach ($courses as $key => $course) {
            $m->addElement('hidden', $key, $course->id);
        }

        $this->generate_states_and_buttons();
    }
}