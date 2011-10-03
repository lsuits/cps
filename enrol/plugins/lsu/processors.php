<?php

require_once dirname(__FILE__) . '/lib.php';

class lsu_semesters extends lsu_source implements semester_processor {
    var $serviceId = 'MOODLE_SEMESTERS';

    function parse_term($term) {
        $year = (int)substr($term, 0, 4);

        $semester_code = substr($term, -2);

        switch ($semester_code) {
            case self::FALL: return array($year - 1, 'Fall');
            case self::SPRING: return array($year, 'Spring');
            case self::SUMMER: return array($year, 'Summer');
            case self::WINTER_INT: return array($year - 1, 'WinterInt');
            case self::SPRING_INT: return array($year, 'SpringInt');
            case self::SUMMER_INT: return array($year, 'SummerInt');
        }
    }

    function format_time($time) {
        return  strftime('%Y-%m-%d', time());
    }

    function semesters($date_threshold) {

        if (is_numeric($date_threshold)) {
            $date_threshold = $this->format_time($date_threshold);
        }

        $xml_semesters = $this->invoke(array($date_threshold));

        $lookup = array();
        $semesters = array();

        foreach($xml_semesters->ROW as $xml_semester) {
            $code = $xml_semester->CODE_VALUE;

            $term = (string) $xml_semester->TERM_CODE;

            $session = (string) $xml_semester->SESSION;

            $date = $this->parse_date($xml_semester->CALENDAR_DATE);

            switch ($code) {
                case self::LSU_SEM:
                case self::LSU_FINAL:
                    $campus = 'LSU';
                    $starting = ($code == self::LSU_SEM);
                    break;
                case self::LAW_SEM:
                case self::LAW_FINAL:
                    $campus = 'LAW';
                    $starting = ($code == self::LAW_SEM);
                    break;
                default: continue;
            }

            if (!isset($lookup[$campus])) {
                $lookup[$campus] = array();
            }

            if ($starting) {
                list($year, $name) = $this->parse_term($term);

                $semester = new stdClass;
                $semester->year = $year;
                $semester->name = $name;
                $semester->campus = $campus;
                $semester->session_key = $session;
                $semester->class_start = $date;

                $semesters[] = $semester;
            } else if (isset($lookup[$campus][$term]) and
                $lookup[$campus][$term]->session_key == $session) {

                $semester =& $lookup[$campus][$term];
                $semester->grades_due = $date;

            } else {
                continue;
            }

            $lookup[$campus][$term] = $semester;
        }

        return $semesters;
    }
}

class lsu_courses extends lsu_source implements course_processor {
    var $serviceId = 'MOODLE_COURSES';

    function courses($semester) {
        $semester_term = $this->encode_semester($semester->year, $semester->name);

        $courses = array();

        $campus = ($semester->campus == 'LSU') ? self::LSU_CAMPUS : self::LAW_CAMPUS;

        $xml_courses = $this->invoke(array($campus, $semester_term, $semester->session_key));

        // Caching strategy
        $by_strategy = $this->course_strategy();

        foreach ($xml_courses->ROW as $xml_course) {
            $department = (string) $xml_course->DEPT_CODE;
            $course_number = (string) $xml_course->COURSE_NBR;

            $is_unique = function ($course) use ($department, $course_number) {
                return ($course->department != $department or
                    $course->cou_number != $course_number);
            };

            if (empty($course) or $is_unique($course)) {
                $course = new stdClass;
                $course->department = $department;
                $course->cou_number = $course_number;
                $course->course_type = (string) $xml_course->CLASS_TYPE;
                $course->course_first_year = (int) $xml_course->COURSE_NBR < 5200 ? 1 : 0;

                $info = lsu_cache::set_and_retrieve($course, $by_strategy);

                $course->fullname = $info->fullname;
                $course->course_grade_type = $info->course_grade_type;

                $course->sections = array();

                $courses[] = $course;
            }

            $section = new stdClass;
            $section->sec_number= (string) $xml_course->SECTION_NBR;

            $course->sections[] = $section;
        }

        return $courses;
    }
}

class lsu_teachers extends lsu_source implements teacher_processor {
    var $serviceId = 'MOODLE_INSTRUCTORS';

    function teachers($semester, $course, $section) {
        $semester_term = $this->encode_semester($semester->year, $semester->name);

        $campus = $semester->campus == 'LSU' ? self::LSU_CAMPUS : self::LAW_CAMPUS;

        $params = array($course->cou_number, $section->sec_number,
            $course->department, $campus, $semester_term, $semester->session_key);

        $xml_teachers = $this->invoke($params);

        $by_strategy = $this->user_strategy();

        $teachers = array();
        foreach ($xml_teachers->ROW as $xml_teacher) {

            $primary_flag = trim($xml_teacher->PRIMARY_INSTRUCTOR);

            $teacher = new stdClass;

            $teacher->idnumber = (string) $xml_teacher->LSU_ID;
            $teacher->primary_flag = (string) $primary_flag == 'Y' ? 1 : 0;

            $info = lsu_cache::set_and_retrieve($teacher, $by_strategy);

            $teacher->firstname = $info->firstname;
            $teacher->lastname = $info->lastname;
            $teacher->username = $info->username;

            $teachers[] = $teacher;
        }

        return $teachers;
    }
}

class lsu_students extends lsu_source implements student_processor {
    var $serviceId = 'MOODLE_STUDENTS';

    function students($semester, $course, $section) {
        $semester_term = $this->encode_semester($semester->year, $semester->name);

        $campus = $semester->campus == 'LSU' ? self::LSU_CAMPUS : self::LAW_CAMPUS;

        $params = array($campus, $semester_term, $course->department,
            $course->cou_number, $section->sec_number, $semester->session_key);

        $xml_students = $this->invoke($params);

        $by_strategy = $this->user_strategy();

        $students = array();
        foreach ($xml_students->ROW as $xml_student) {

            $student = new stdClass;

            $student->idnumber = (string) $xml_student->LSU_ID;
            $student->credit_hours = (string) $xml_student->CREDIT_HRS;

            $info = lsu_cache::set_and_retrieve($student, $by_strategy);

            $student->username = $info->username;
            $student->firstname = $info->firstname;
            $student->lastname = $info->lastname;
            $student->user_ferpa = $info->user_ferpa;

            $students[] = $student;
        }

        return $students;
    }
}

class lsu_student_data extends lsu_source {
    var $serviceId = 'MOODLE_STUDENT_DATA';

    function student_data($semester) {
        $semester_term = $this->encode_semester($semester->year, $semester->name);

        $params = array($semester_term);

        if ($semester->campus == 'LSU') {
            $params += array(self::LSU_INST);
        } else {
            $params += array(self::LAW_INST);
        }

        $xml_data = $this->invoke($params);

        $student_data = array();

        foreach ($xml_data->ROW as $xml_student_data) {
            $stud_data = new stdClass;

            $reg = trim($xml_student_data->REGISTRATION_DATE);

            $stud_data->user_year = (string) $xml_student_data->YEAR_CLASS;
            $stud_data->user_college = (string) $xml_student_data->COLLEGE_CODE;
            $stud_data->user_major = (string) $xml_student_data->CURRIC_CODE;
            $stud_data->user_reg_status = empty($reg) ? NULL : $this->parse_date($reg);
            $stud_data->user_keypadid = (string) $xml_student_data->KEYPADID;
            $stud_data->idnumber = (string) $xml_student_data->LSU_ID;

            $student_data[$stud_data->idnumber] = $stud_data;
        }

        return $student_data;
    }
}

class lsu_degree extends lsu_source {
    var $serviceId = 'MOODLE_DEGREE_CANDIDATE';

    function graduates($semester) {
        $term = $this->encode_semester($semester->year, $semester->name);

        $params = array($term);

        if ($semester->campus == 'LSU') {
            $params += array(self::LSU_CAMPUS, self::LSU_INST);
        } else {
            $params += array(self::LAW_CAMPUS, self::LAW_INST);
        }

        $xml_grads = $this->invoke($params);

        $graduates = array();
        foreach($xml_grads->ROW as $xml_grad) {
            $graduate = new stdClass;

            $graduate->idnumber = (string) $xml_grad->LSU_ID;
            $graduate->degree = 'Y';

            $graduates[$graduate->idnumber] = $graduate;
        }

        return $graduates;
    }
}

class lsu_anonymous extends lsu_source {
    var $serviceId = 'MOODLE_LAW_ANON_NBR';

    function anonymous_numbers($semester) {
        $term = $this->encode_semester($semester->year, $semester->name);

        $xml_numbers = $this->invoke(array($term));

        $numbers = array();
        foreach ($xml_numbers->ROW as $xml_number) {
            $number = new stdClass;

            $number->idnumber = (string) $xml_number->LSU_ID;
            $number->user_anonymous_number = (string) $xml_number->LAW_ANONYMOUS_NBR;

            $numbers[$number->idnumber] = $number;
        }

        return $numbers;
    }
}

final class lsu_course_cache_strategy extends lsu_source implements lsu_cache_strategy {
    var $serviceId = 'MOODLE_COURSE_INFO';

    public function id() {
        return 'course_cache';
    }

    public function key($what) {
        return "$what->department$what->cou_number$what->course_type";
    }

    public function pull($what) {
        $course_info = $this->invoke(array(
            $what->department, $what->cou_number, $what->course_type)
        )->ROW;

        $info = new stdClass;
        $info->fullname = (string) $course_info->COURSE_TITLE;
        $info->course_grade_type = (string) $course_info->GRADE_SYSTEM_CODE;

        return $info;
    }
}

final class lsu_user_cache_strategy extends lsu_source implements lsu_cache_strategy {
    var $serviceId = 'MOODLE_PROFILE_INFO';

    public function id() {
        return 'user_cache';
    }

    public function key($what) {
        return $what->idnumber;
    }

    public function pull($what) {
        $profile_info = $this->invoke(array($what->idnumber))->ROW;

        list($first, $last) = $this->parse_name($profile_info->INDIV_NAME);

        $info = new stdClass;
        $info->username = (string) $profile_info->PRIMARY_ACCESS_ID;
        $info->user_ferpa = (string) $profile_info->WITHHOLD_DIR_FLG == 'N' ? 0 : 1;
        $info->firstname = $first;
        $info->lastname = $last;

        return $info;
    }
}

