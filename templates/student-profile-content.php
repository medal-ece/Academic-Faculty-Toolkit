<?php
/**
 * Student profile content injected into the active theme page template.
 *
 * Available variables:
 * - $student: resolved student record.
 */

if (!defined('ABSPATH')) {
    exit;
}

$img_id = AcademicDirectory::get_image_id($student['image']);
$profile_links = AcademicDirectory::get_student_external_links($student);
?>

<div class="academic-student-profile">
    <div class="student-profile-header">
        <div class="student-profile-photo-wrap">
            <?php if ($img_id): ?>
                <?php echo wp_get_attachment_image($img_id, 'large', false, array('class' => 'student-profile-photo', 'alt' => $student['name'])); ?>
            <?php else: ?>
                <div class="student-profile-photo student-profile-photo-placeholder"></div>
            <?php endif; ?>
        </div>

        <div class="student-profile-intro">
            <p class="student-profile-category"><?php echo esc_html($student['category']); ?></p>
            <h1><?php echo esc_html($student['name']); ?></h1>

            <div class="student-profile-title-row">
                <?php if (!empty($student['pronoun'])): ?>
                    <p class="student-profile-pronoun"><?php echo esc_html($student['pronoun']); ?></p>
                <?php endif; ?>

                <?php if (!empty($student['secondary_email']) || !empty($profile_links)): ?>
                    <div class="student-profile-links" aria-label="Profile links">
                        <?php if (!empty($student['secondary_email'])): ?>
                            <a class="profile-link profile-icon-link profile-email academic-email-link" href="#" data-academic-email="<?php echo esc_attr(AcademicDirectory::get_protected_email_data($student['secondary_email'])); ?>" title="Email" aria-label="Email">
                                <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                                <span class="profile-icon-fallback" aria-hidden="true">@</span>
                            </a>
                        <?php endif; ?>

                        <?php foreach ($profile_links as $profile_link): ?>
                            <a class="profile-link profile-icon-link profile-<?php echo esc_attr(str_replace('_', '-', $profile_link['field'])); ?>" href="<?php echo esc_url($profile_link['url']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr($profile_link['label']); ?>" aria-label="<?php echo esc_attr($profile_link['label']); ?>">
                                <?php if (!empty($profile_link['icon_class'])): ?>
                                    <i class="<?php echo esc_attr($profile_link['icon_class']); ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <span class="profile-icon-fallback" aria-hidden="true"><?php echo esc_html($profile_link['icon']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="student-profile-content">
        <?php if (!empty($student['bio'])): ?>
            <section class="profile-section profile-section-bio">
                <h2>Bio</h2>
                <?php echo wp_kses_post(wpautop($student['bio'])); ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($student['research_interests'])): ?>
            <section class="profile-section profile-section-interests">
                <h2>Research Interests</h2>
                <?php $research_interests = array_values(array_filter(array_map('trim', explode(';', $student['research_interests'])))); ?>
                <?php if (count($research_interests) > 1): ?>
                    <ul class="student-profile-interest-list">
                        <?php foreach ($research_interests as $interest): ?>
                            <li><?php echo esc_html($interest); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p><?php echo esc_html($student['research_interests']); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($student['current_position'])): ?>
            <section class="profile-section profile-section-current-position">
                <h2>Current Position</h2>
                <p><?php echo esc_html($student['current_position']); ?></p>
                <?php if (!empty($student['position_updated'])): ?>
                    <p class="student-profile-date"><?php echo esc_html('Updated: ' . $student['position_updated']); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($student['education'])): ?>
            <section class="profile-section profile-section-education">
                <h2>Education</h2>
                <div class="student-profile-education">
                    <?php foreach ($student['education'] as $education): ?>
                        <article>
                            <h3><?php echo esc_html($education['education_title']); ?></h3>

                            <?php if (!empty($education['institution'])): ?>
                                <p>
                                    <?php if (!empty($education['university_link'])): ?>
                                        <a href="<?php echo esc_url($education['university_link']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($education['institution']); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($education['institution']); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($education['start_date']) || !empty($education['end_date'])): ?>
                                <p class="student-profile-date">
                                    <?php echo esc_html($education['start_date']); ?>
                                    <?php if (!empty($education['start_date'])): ?>
                                        -
                                    <?php endif; ?>
                                    <?php echo esc_html(!empty($education['end_date']) ? $education['end_date'] : 'Present'); ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($student['hobbies'])): ?>
            <section class="profile-section profile-section-hobbies">
                <h2>Hobbies</h2>
                <?php $hobbies = array_values(array_filter(array_map('trim', explode(';', $student['hobbies'])))); ?>
                <?php if (count($hobbies) > 1): ?>
                    <ul class="student-profile-hobby-list">
                        <?php foreach ($hobbies as $hobby): ?>
                            <li><?php echo esc_html($hobby); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p><?php echo esc_html(reset($hobbies)); ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
