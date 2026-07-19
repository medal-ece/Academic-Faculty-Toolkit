<?php
/**
 * PI profile content injected into the active theme page template.
 *
 * Available variables:
 * - $pi: resolved principal investigator record.
 */

if (!defined('ABSPATH')) {
    exit;
}

$img_id = AcademicDirectory::get_image_id($pi['image']);
$affiliation = trim($pi['department'] . ', ' . $pi['institution'], ' ,');
$full_bio = !empty($pi['full_bio']) ? $pi['full_bio'] : (!empty($pi['bio']) ? $pi['bio'] : '');
$profile_links = AcademicDirectory::get_pi_external_links($pi, false, true);
$list_sections = array(
    'Education' => !empty($pi['education']) ? $pi['education'] : '',
    'Professional Experience' => !empty($pi['professional_experience']) ? $pi['professional_experience'] : '',
    'Honors and Awards' => !empty($pi['honors_awards']) ? $pi['honors_awards'] : '',
);
?>

<div class="academic-student-profile academic-pi-profile">
    <div class="student-profile-header pi-profile-header">
        <div class="student-profile-photo-wrap">
            <?php if ($img_id): ?>
                <?php echo wp_get_attachment_image($img_id, 'large', false, array('class' => 'student-profile-photo pi-profile-photo', 'alt' => $pi['name'])); ?>
            <?php else: ?>
                <div class="student-profile-photo pi-profile-photo student-profile-photo-placeholder"></div>
            <?php endif; ?>
        </div>

        <div class="student-profile-intro">
            <h1><?php echo esc_html($pi['name']); ?></h1>

            <?php if (!empty($pi['title']) || !empty($affiliation)): ?>
                <p class="student-profile-pronoun">
                    <?php echo esc_html($pi['title']); ?>
                    <?php if (!empty($pi['title']) && !empty($affiliation)): ?>
                        <br>
                    <?php endif; ?>
                    <?php echo esc_html($affiliation); ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($profile_links)): ?>
                <div class="student-profile-links" aria-label="PI profile links">
                    <?php foreach ($profile_links as $profile_link): ?>
                        <a class="profile-link profile-icon-link profile-<?php echo esc_attr(str_replace('_', '-', $profile_link['field'])); ?><?php echo !empty($profile_link['is_email']) ? ' academic-email-link' : ''; ?>" href="<?php echo esc_url($profile_link['url']); ?>" <?php if (empty($profile_link['is_email'])): ?>target="_blank" rel="noopener noreferrer"<?php else: ?>data-academic-email="<?php echo esc_attr($profile_link['data_email']); ?>"<?php endif; ?> title="<?php echo esc_attr($profile_link['label']); ?>" aria-label="<?php echo esc_attr($profile_link['label']); ?>">
                            <i class="<?php echo esc_attr($profile_link['icon_class']); ?>" aria-hidden="true"></i>
                            <span class="profile-icon-fallback" aria-hidden="true"><?php echo esc_html($profile_link['icon']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="student-profile-content">
        <?php if (!empty($full_bio)): ?>
            <section class="profile-section profile-section-bio">
                <h2>Biography</h2>
                <?php echo wp_kses_post(wpautop($full_bio)); ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($pi['research_interests'])): ?>
            <section class="profile-section profile-section-interests">
                <h2>Research Interests</h2>
                <div class="pi-interests pi-profile-interests" aria-label="Research interests">
                    <?php foreach ($pi['research_interests'] as $interest): ?>
                        <span><?php echo esc_html($interest); ?></span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php foreach ($list_sections as $section_title => $section_content): ?>
            <?php if (trim($section_content) === '') continue; ?>
            <section class="profile-section profile-section-rich">
                <h2><?php echo esc_html($section_title); ?></h2>
                <div class="pi-profile-rich-text">
                    <?php echo wp_kses_post(wpautop($section_content)); ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if (!empty($pi['useful_text'])): ?>
            <section class="profile-section profile-section-useful">
                <h2><?php echo esc_html(!empty($pi['useful_title']) ? $pi['useful_title'] : 'Useful Links'); ?></h2>
                <div class="pi-profile-rich-text">
                    <?php echo wp_kses_post(wpautop($pi['useful_text'])); ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($pi['office']) || !empty($pi['phone'])): ?>
            <section class="profile-section profile-section-contact">
                <h2>Contact</h2>
                <p class="profile-contact-line">
                    <?php if (!empty($pi['office'])): ?>
                        <span class="pi-contact-item pi-office"><?php echo esc_html($pi['office']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($pi['phone'])): ?>
                        <span class="pi-contact-item pi-phone"><?php echo esc_html($pi['phone']); ?></span>
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
    </div>
</div>
