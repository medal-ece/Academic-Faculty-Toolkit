<?php
/**
 * Principal investigator section markup.
 *
 * Available variables:
 * - $pi: PI data from the CSV.
 */

if (!defined('ABSPATH')) {
    exit;
}

$img_id = AcademicDirectory::get_image_id($pi['image']);
$affiliation = trim($pi['department'] . ', ' . $pi['institution'], ' ,');
$profile_links = AcademicDirectory::get_pi_external_links($pi, true, true);
?>

<section class="pi-section">
    <div class="pi-media">
        <?php if ($img_id): ?>
            <?php echo wp_get_attachment_image($img_id, 'large', false, array('class' => 'pi-photo', 'alt' => $pi['name'])); ?>
        <?php else: ?>
            <div class="pi-photo pi-photo-placeholder"></div>
        <?php endif; ?>
    </div>

    <div class="pi-details">
        <h2 class="pi-name"><?php echo esc_html($pi['name']); ?></h2>

        <?php if (!empty($pi['title']) || !empty($affiliation)): ?>
            <p class="pi-title">
                <?php echo esc_html($pi['title']); ?>
                <?php if (!empty($pi['title']) && !empty($affiliation)): ?>
                    <br>
                <?php endif; ?>
                <?php echo esc_html($affiliation); ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($pi['bio'])): ?>
            <p class="pi-bio" style="color: #666;"><?php echo esc_html($pi['bio']); ?></p>
        <?php endif; ?>

        <?php if (!empty($pi['research_interests'])): ?>
            <div class="pi-interests" aria-label="Research interests">
                <?php foreach ($pi['research_interests'] as $interest): ?>
                    <span><?php echo esc_html($interest); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="pi-contact profile-icon-row" aria-label="PI links">
            <?php foreach ($profile_links as $profile_link): ?>
                <a class="pi-contact-link profile-icon-link profile-<?php echo esc_attr(str_replace('_', '-', $profile_link['field'])); ?><?php echo !empty($profile_link['is_email']) ? ' academic-email-link' : ''; ?>" href="<?php echo esc_url($profile_link['url']); ?>" <?php if (empty($profile_link['is_email'])): ?>target="_blank" rel="noopener noreferrer"<?php else: ?>data-academic-email="<?php echo esc_attr($profile_link['data_email']); ?>"<?php endif; ?> title="<?php echo esc_attr($profile_link['label']); ?>" aria-label="<?php echo esc_attr($profile_link['label']); ?>">
                    <i class="<?php echo esc_attr($profile_link['icon_class']); ?>" aria-hidden="true"></i>
                    <span class="profile-icon-fallback" aria-hidden="true"><?php echo esc_html($profile_link['icon']); ?></span>
                </a>
            <?php endforeach; ?>

            <?php if (!empty($pi['office'])): ?>
                <span class="pi-contact-item pi-office"><?php echo esc_html($pi['office']); ?></span>
            <?php endif; ?>

            <?php if (!empty($pi['phone'])): ?>
                <span class="pi-contact-item pi-phone"><?php echo esc_html($pi['phone']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</section>
