<?php
/**
 * Student card markup.
 *
 * Available variables:
 * - $student: student data from the CSV.
 */

if (!defined('ABSPATH')) {
    exit;
}

$img_id = AcademicDirectory::get_image_id($student['image']);
$is_current = !empty($student['active']) && in_array(strtolower(trim((string) $student['active'])), array('1', 'active', 'true', 'y', 'yes'), true);
$member_section = isset($member_section) ? (string) $member_section : ($is_current ? 'current' : 'past');
$class_name = isset($class_name) ? (string) $class_name : (!empty($student['category']) ? (string) $student['category'] : '');
$search_text = implode(' ', array_filter(array(
    isset($student['name']) ? $student['name'] : '',
    $class_name,
    isset($student['title']) ? $student['title'] : '',
    isset($student['research_interests']) ? $student['research_interests'] : '',
    isset($student['current_position']) ? $student['current_position'] : '',
    isset($student['bio']) ? $student['bio'] : '',
    isset($student['date_of_entry']) ? $student['date_of_entry'] : '',
)));
?>

<div class="student-card" data-member-card data-member-status="<?php echo esc_attr($member_section); ?>" data-member-category="<?php echo esc_attr($class_name); ?>" data-member-search="<?php echo esc_attr(wp_strip_all_tags($search_text)); ?>">
    <div class="photo-wrapper">
        <?php if ($img_id): ?>
            <?php echo wp_get_attachment_image($img_id, 'medium', false, array('class' => 'student-photo', 'alt' => $student['name'])); ?>
        <?php else: ?>
            <div class="photo-placeholder"></div>
        <?php endif; ?>
    </div>

    <div class="details">
        <span class="name"><?php echo esc_html($student['name']); ?></span>

        <?php if (!empty($student['date_of_entry'])): ?>
            <span class="student-entry-date academic-mini-badge"><?php echo esc_html('Joined ' . $student['date_of_entry']); ?></span>
        <?php endif; ?>

        <?php if (!$is_current && !empty($student['current_position'])): ?>
            <p class="student-current-position">
                <?php echo esc_html($student['current_position']); ?>
                <?php if (!empty($student['position_updated'])): ?>
                    <span><?php echo esc_html('Updated: ' . $student['position_updated']); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="meta-links profile-icon-row" aria-label="<?php echo esc_attr('Links for ' . $student['name']); ?>">
            <?php if (!empty($student['profile_url'])): ?>
                <a class="meta-link profile-icon-link meta-profile" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($student['profile_url']); ?>" title="Profile" aria-label="<?php echo esc_attr('Profile for ' . $student['name']); ?>">
                    <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                    <span class="profile-icon-fallback" aria-hidden="true">Profile</span>
                </a>
            <?php endif; ?>

            <?php if (!empty($student['secondary_email'])): ?>
                <a class="meta-link profile-icon-link meta-email academic-email-link" href="#" data-academic-email="<?php echo esc_attr(AcademicDirectory::get_protected_email_data($student['secondary_email'])); ?>" title="Email" aria-label="<?php echo esc_attr('Email ' . $student['name']); ?>">
                    <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                    <span class="profile-icon-fallback" aria-hidden="true">@</span>
                </a>
            <?php endif; ?>

        </div>
    </div>

    <?php if (!empty($student['profile_url'])): ?>
        <a class="student-card-link" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($student['profile_url']); ?>" aria-label="<?php echo esc_attr('View profile for ' . $student['name']); ?>"></a>
    <?php endif; ?>
</div>
