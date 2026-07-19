<?php
/**
 * Backward-compatible template alias.
 *
 * The full page template now lives in student-directory-page.php.
 */

if (!defined('ABSPATH')) {
    exit;
}
echo AcademicDirectory::render_template('student-directory-page.php', array(
    'groups' => $groups,
    'principal_investigator' => isset($principal_investigator) ? $principal_investigator : array(),
    'atts' => isset($atts) ? $atts : array(),
));
