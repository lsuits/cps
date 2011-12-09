<?php

require_once $CFG->dirroot . '/enrol/cps/publiclib.php';
cps::require_daos();

abstract class cps_preferences extends cps_external {
    public static function settings() {
        $settings = array('creation', 'split', 'crosslist',
            'team_request', 'material', 'unwant');

        $remaining_settings = array();

        foreach ($settings as $setting) {
            $class = 'cps_' . $setting;

            if (!$class::is_enabled()) {
                continue;
            }

            $remaining_settings[$setting] = $class::name();
        }

        return $remaining_settings;
    }

    public static function is_enabled() {
        $setting = self::call('get_name');

        return get_config('block_cps', $setting);
    }

    public static function name() {
        return get_string(self::call('get_name'), 'block_cps');
    }

}

interface verifiable {
    public static function is_valid($courses);
}

interface application {
    function apply();
}

interface undoable {
    function unapply();
}

interface unique extends application {
    function new_idnumber();
}

abstract class cps_section_accessor extends cps_preferences {
    var $section;

    public function section() {
        if (empty($this->section)) {
            $section = cps_section::get(array('id' => $this->sectionid));

            $this->section = $section;
        }

        return $this->section;
    }
}

abstract class cps_user_section_accessor extends cps_section_accessor {
    var $user;

    public function user() {
        if (empty($this->user)) {
            $user = cps_user::get(array('id' => $this->userid));

            $this->user = $user;
        }

        return $this->user;
    }
}

// Begin Concrete classes
class cps_unwant extends cps_user_section_accessor implements application, undoable {
    var $sectionid;
    var $userid;

    public static function active_sections_for($teacher, $is_primary = true) {
        $sections = $teacher->sections($is_primary);

        return self::active_sections($sections, $teacher->userid);
    }

    public static function active_sections(array $sections, $userid = null) {
        global $USER;

        if (!$userid) {
            $userid = $USER->id;
        }

        $unwants = cps_unwant::get_all(array('userid' => $userid));

        foreach ($unwants as $unwant) {
            if (isset($sections[$unwant->sectionid])) {
                unset($sections[$unwant->sectionid]);
            }
        }

        return $sections;
    }

    function apply() {
        $section = $this->section();

        // Severage is happening in eventslib.php
        cps::unenroll_users(array($section));
    }

    function unapply() {
        $section = $this->section();

        cps::enroll_users(array($section));
    }
}

class cps_material extends cps_preferences implements application {
    var $userid;
    var $courseid;
    var $moodleid;

    function apply() {
        global $DB, $CFG;

        require_once $CFG->dirroot . '/course/lib.php';

        $cps_course = cps_course::get(array('id' => $this->courseid));
        $user = cps_user::get(array('id' => $this->userid));

        $pattern = get_config('block_cps', 'material_shortname');

        $a = new stdClass;
        $a->department = $cps_course->department;
        $a->course_number = $cps_course->cou_number;
        $a->fullname = fullname($user);

        $shortname = cps::format_string($pattern, $a);

        $mcourse = $DB->get_record('course', array('shortname' => $shortname));

        $enrol = enrol_get_plugin('cps');

        if (!$mcourse) {
            $cat_params = array('name' => $cps_course->department);
            $cat = $DB->get_field('course_categories', 'id', $cat_params);

            $course = new stdClass;
            $course->visible = 0;
            $course->numsections = $enrol->setting('course_numsections');
            $course->format = $enrol->setting('course_format');

            $course->fullname = $shortname;
            $course->shortname = $shortname;
            $course->summary = $shortname;
            $course->category = $cat;

            $mcourse = create_course($course);
        }

        $instance = $enrol->get_instance($mcourse->id);

        $primary = $enrol->setting('editingteacher_role');
        $enrol->enrol_user($instance, $user->id, $primary);

        $this->moodleid = $mcourse->id;

        return true;
    }
}

class cps_creation extends cps_preferences implements application {
    var $userid;
    var $semesterid;
    var $courseid;
    var $enroll_days;
    var $create_days;

    function apply() {
        // TODO: Should we run a reprocess on a creation / enroll day change?
    }
}

class cps_setting extends cps_preferences {
}

class cps_split extends cps_user_section_accessor implements unique, undoable, verifiable {
    var $userid;
    var $sectionid;
    var $groupingid;

    public static function is_valid($courses) {
        $valids = self::filter_valid($courses);
        return !empty($valids);
    }

    public static function filter_valid($courses) {
        return array_filter($courses, function ($course) {
            return count($course->sections) > 1;
        });
    }

    public static function in_course($course) {
        global $USER;

        if (empty($course->sections)) {
            $course->sections = array();

            $teacher = cps_teacher::get(array('id' => $USER->id));

            $sections = cps_unwant::active_sections_for($teacher, true);

            foreach ($sections as $section) {
                $course->sections[$section->id] = $section;
            }
        }

        $course_section_ids = implode(',', array_keys($course->sections));

        $split_filters = array(
            'userid' => $USER->id,
            'sectionid IN (' . $course_section_ids . ')'
        );

        $splits = self::get_select($split_filters);

        return $splits;
    }

    public static function exists($course) {
        return self::in_course($course) ? true : false;
    }

    public static function groups($splits) {
        if (empty($splits)) {
            return 0;
        }

        return array_reduce($splits, function ($in, $split) {
            return $split->groupingid > $in ? $split->groupingid : $in;
        });
    }

    function new_idnumber() {
        $section = $this->section();
        $semester = $section->semester();

        $course = $section->course();

        $idnumber = sprintf('%s%s%s%s%ssplit%s', $semester->year, $semester->name,
            $course->department, $course->cou_number, $this->userid,
            $this->groupingid);

        return $idnumber;
    }

    function apply() {
        $sections = array($this->section());

        cps::inject_manifest($sections);
    }

    function unapply() {
        $sections = array($this->section());

        cps::inject_manifest($sections, function ($sec) {
            $sec->idnumber = null;
        });
    }
}

class cps_crosslist extends cps_user_section_accessor implements unique, undoable, verifiable {
    var $userid;
    var $sectionid;
    var $groupingid;
    var $shell_name;

    public static function is_valid($courses) {
        if (count($courses) <= 1) {
            return false;
        }

        // Must have two courses in the same semester
        $semesters = array();
        foreach ($courses as $course) {
            $semid = reset($course->sections)->semesterid;

            if (!isset($semesters[$semid])) {
                $semesters[$semid] = 0;
            }

            $semesters[$semid]++;
        }

        $validation = function ($in, $count) {
            return ($in || $count > 1);
        };

        return array_reduce($semesters, $validation, false);
    }

    public static function in_courses(array $courses) {
        global $USER;

        // Flatten sections
        $course_to_sectionids = function ($course) {
            return implode(',', array_keys($course->sections));
        };

        $sectionids = implode(',', array_map($course_to_sectionids, $courses));

        $crosslist_params = array(
            'userid = ' . $USER->id,
            'sectionid IN (' . $sectionids . ')'
        );

        $crosslists = self::get_select($crosslist_params);

        return $crosslists;
    }

    public static function exists($course) {
        $courses = is_array($course) ? $course : array($course);

        return self::in_courses($courses) ? true : false;
    }

    public static function groups($crosslists) {
        if (empty($crosslists)) {
            return 0;
        }

        return array_reduce($crosslists, function ($in, $crosslist) {
            return $crosslist->groupingid > $in ? $crosslist->groupingid : $in;
        });
    }

    function new_idnumber() {
        $section = $this->section();
        $sem = $section->semester();

        $shell = str_replace(' ', '', trim($this->shell_name));
        $userid = $this->userid;

        $idnumber = "$sem->year$sem->name{$shell}{$userid}cl{$this->groupingid}";
        return $idnumber;
    }

    function apply() {
        $section = $this->section();

        cps::inject_manifest(array($section));
    }

    function unapply() {
        $sections = array($this->section());

        cps::inject_manifest($sections, function ($section) {
            $section->idnumber = null;
        });
    }
}

// Request application involves emails
class cps_team_request extends cps_preferences implements application, undoable {
    var $semesterid;
    var $userid;
    var $courseid;
    var $requested;
    var $requested_course;
    var $approval_flag;

    public static function in_course($course, $semester, $approved = false) {
        global $USER;

        $params = array(
            'userid' => $USER->id,
            'courseid' => $course->id,
            'semesterid' => $semester->id
        );

        $requests = cps_team_request::get_all($params);

        $params = array(
            'requested' => $USER->id,
            'requested_course' => $course->id,
            'semesterid' => $semester->id
        );

        $participants = cps_team_request::get_all($params);

        $all_together = $requests + $participants;

        if ($approved) {
            $rtn = array_filter($all_together, function ($req) {
                return $req->approved();
            });

            return $rtn;
        } else {
            return $all_together;
        }
    }

    public static function exists($course, $semester) {
        return self::in_course($course, $semester) ? true : false;
    }

    public static function groups($teamteaches) {
        if (empty($teamteaches)) {
            return 0;
        }

        $courseids = array();
        foreach ($teamteaches as $teamteach) {
            $courseids[] = $teamteach->requested_course;
        }

        return count(array_unique($courseids));
    }

    public static function delete($id) {
        $params = array('id' => $id);

        return self::delete_all_internal($params, function($table) use ($params) {
            $old = cps_team_request::get($params);

            $child_params = array('requestid' => $old->id);

            cps_team_section::delete_all($child_params);
        });
    }

    public static function delete_all(array $params) {
        return self::delete_all_internal($params, function ($t) use ($params) {
            $old = cps_team_request::get_all($params);

            foreach ($old as $request) {
                $child_params = array('requestid' => $request->id);

                cps_team_section::delete_all($child_params);
            }
        });
    }

    public static function filtered_master($requests, $userid = null) {
        if (empty($userid)) {
            global $USER;

            $userid = $USER->id;
        }

        return array_filter($requests, function ($req) use ($userid) {
            return $req->is_owner($userid);
        });
    }

    public function is_owner($from_userid = null) {
        if (!$from_userid) {
            global $USER;

            $from_userid = $USER->id;
        }

        return $from_userid == $this->userid;
    }

    public function approved() {
        return $this->approval_flag == 1;
    }

    public function other_course() {
        if (empty($this->other_course)) {
            $course = cps_course::get(array('id' => $this->requested_course));

            $this->other_course = $course;
        }

        return $this->other_course;
    }

    public function other_user() {
        if (empty($this->other_user)) {
            $this->other_user = cps_user::get(array('id' => $this->requested));
        }

        return $this->other_user;
    }

    public function other_teacher() {
        $course = $this->other_course();

        $teachers = $course->teachers($this->semester());

        foreach ($teachers as $teacher) {
            if ($teacher->userid == $this->requested) {
                return $teacher;
            }
        }

        return false;
    }

    public function course() {
        if (empty($this->course)) {
            $this->course = cps_course::get(array('id' => $this->courseid));
        }

        return $this->course;
    }

    public function owner() {
        if (empty($this->owner)) {
            $this->owner = cps_user::get(array('id' => $this->userid));
        }

        return $this->owner;
    }

    public function semester() {
        if (empty($this->semester)) {
            $this->semester = cps_semester::get(array('id' => $this->semesterid));
        }

        return $this->semester;
    }

    public function label($from_userid = null) {

        if ($this->is_owner($from_userid)) {
            $course = $this->other_course();
            $user = $this->other_user();
        } else {
            $course = $this->course();
            $user = $this->owner();
        }

        $sem = $this->semester();

        $label = "$sem->year $sem->name $course->department $course->cou_number";

        return $label . ' with ' . fullname($user);
    }

    public function sections() {
        if (empty($this->sections)) {
            $params = array('requestid' => $this->id);

            $this->sections = cps_team_section::get_all($params);
        }

        return $this->sections;
    }

    private function build_email_obj() {
        $requester = $this->owner();
        $requestee = $this->other_user();

        $course_name = function($course) {
            return "$course->department $course->cou_number";
        };

        $a = new stdClass;
        $a->requestee = fullname($requestee);
        $a->requester = fullname($requester);
        $a->other_course = $course_name($this->other_course());
        $a->course = $course_name($this->course());

        return $a;
    }

    function apply() {
        $_s = cps::gen_str('block_cps');

        $a = $this->build_email_obj();

        if ($this->approved()) {
            $subject_key = 'team_request_approved_subject';
            $body_key = 'team_request_approved_body';

            $to = $this->owner();
            $from = $this->other_user();
        } else {
            $subject_key = 'team_request_invite_subject';
            $body_key = 'team_request_invite_body';

            $a->link = new moodle_url('/blocks/cps/team_request.php');

            $to = $this->other_user();
            $from = $this->owner();
        }

        email_to_user($to, $from, $_s($subject_key), $_s($body_key, $a));
    }

    function unapply() {
        global $USER;

        $requester = $this->owner();
        $requestee = $this->other_user();

        $_s = cps::gen_str('block_cps');

        $a = $this->build_email_obj();

        if ($requester->id == $USER->id) {
            $subject_key = 'team_request_revoke_subject';
            $body_key = 'team_request_revoke_subject';

            $to = $requestee;
            $from = $requester;
        } else {
            $subject_key = 'team_request_reject_subject';
            $body_key = 'team_request_reject_body';

            $to = $requester;
            $from = $requestee;
        }

        // Cascading undo
        $children = $this->sections();
        foreach ($children as $child) {
            $child->delete($child->id);
            $child->unapply();
        }

        email_to_user($to, $from, $_s($subject_key), $_s($body_key, $a));
    }
}

class cps_team_section extends cps_section_accessor implements unique, undoable {
    var $sectionid;
    var $groupingid;
    var $shell_name;
    var $requestid;

    public static function in_requests(array $requests) {
        $sections = array();

        foreach ($requests as $request) {
            $params = array('requestid' => $request->id);
            $internal = cps_team_section::get_all($params);

            $sections += $internal;
        }

        return $sections;
    }

    public static function in_sections($requests, $sections) {
        $all_sections = self::in_requests($requests);

        $correct = array();

        foreach ($all_sections as $id => $sec) {
            if (isset($sections[$sec->sectionid])) {
                $correct[$id] = $sec;
            }
        }
        return $correct;
    }

    public static function exists($section) {
        return cps_team_section::get(array('sectionid' => $section->id));
    }

    public static function groups($sections) {
        if (empty($sections)) {
            return 0;
        }

        return array_reduce($sections, function ($in, $sec) {
            return $sec->groupingid > $in ? $sec->groupingid : $in;
        });
    }

    public static function merge_groups($sections) {
        $merged = array();

        if (empty($sections)) {
            return $merged;
        }

        foreach (range(1, self::groups($sections)) as $number) {
            $by_number = function ($section) use ($number) {
                return $section->groupingid == $number;
            };

            $merged[$number] = array_filter($sections, $by_number);
        }

        return $merged;
    }

    public static function merge_groups_in_requests($requests) {
        return self::merge_groups(self::in_requests($requests));
    }

    public function request() {
        if (empty($this->request)) {
            $this->request = cps_team_request::get(array('id' => $this->requestid));
        }

        return $this->request;
    }

    function new_idnumber() {
        $section = $this->section();
        $sem = $section->semester();

        $shell = str_replace(' ', '', trim($this->shell_name));

        $requestid = $this->requestid;

        $idnumber = "$sem->year$sem->name{$shell}{$requestid}tt{$this->groupingid}";

        return $idnumber;
    }

    function apply() {
        $section = $this->section();

        cps::inject_manifest(array($section));
    }

    function unapply() {
        cps::inject_manifest(array($this->section()), function($sec) {
            $sec->idnumber = null;
        });
    }
}
