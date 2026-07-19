<?php
/**
 * Full student directory page markup.
 *
 * Available variables:
 * - $principal_investigator: PI data from data/principal-investigator.csv.
 * - $groups: member sections, then categories, then students.
 * - $atts: shortcode attributes.
 */

if (!defined('ABSPATH')) {
    exit;
}

$member_section_labels = array(
    'current' => 'Current Researchers',
    'past' => 'Past Members',
);
?>

<div class="academic-container">
    <?php if (!empty($principal_investigator)): ?>
        <?php echo AcademicDirectory::render_template('partials/pi-section.php', array('pi' => $principal_investigator)); ?>
        <?php if (!empty($principal_investigator['vacancies_enabled']) && (!empty($principal_investigator['vacancies_title']) || !empty($principal_investigator['vacancies_text']))): ?>
            <section class="academic-vacancies">
                <div>
                    <p class="academic-vacancies-kicker"><?php echo esc_html(!empty($principal_investigator['vacancies_label']) ? $principal_investigator['vacancies_label'] : 'Open positions'); ?></p>
                    <?php if (!empty($principal_investigator['vacancies_title'])): ?>
                        <h2><?php echo esc_html($principal_investigator['vacancies_title']); ?></h2>
                    <?php endif; ?>
                    <?php if (!empty($principal_investigator['vacancies_text'])): ?>
                        <div class="academic-vacancies-text"><?php echo wp_kses_post(wpautop($principal_investigator['vacancies_text'])); ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($principal_investigator['vacancies_button_url']) && !empty($principal_investigator['vacancies_button_text'])): ?>
                    <a class="academic-vacancies-button" href="<?php echo esc_url($principal_investigator['vacancies_button_url']); ?>"><?php echo esc_html($principal_investigator['vacancies_button_text']); ?></a>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <div class="academic-member-filters" data-member-filters>
        <label>
            <span><?php echo esc_html('Search people'); ?></span>
            <input type="search" data-member-search-input placeholder="<?php echo esc_attr('Search name, title, interests, or category'); ?>">
        </label>
        <label>
            <span><?php echo esc_html('Member group'); ?></span>
            <select data-member-status-filter>
                <option value=""><?php echo esc_html('All members'); ?></option>
                <?php foreach ($member_section_labels as $member_section_key => $member_section_label): ?>
                    <option value="<?php echo esc_attr($member_section_key); ?>"><?php echo esc_html($member_section_label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <p class="academic-member-filter-empty" data-member-filter-empty hidden><?php echo esc_html('No members match your search.'); ?></p>

    <?php foreach ($groups as $member_section => $categories): ?>
        <section class="member-section" data-member-section>
            <h2 class="member-section-title"><?php echo esc_html(isset($member_section_labels[$member_section]) ? $member_section_labels[$member_section] : $member_section); ?></h2>

            <?php foreach ($categories as $class_name => $students): ?>
                <section class="student-level-section" data-member-category-section>
                    <h3 class="class-title"><?php echo esc_html($class_name); ?></h3>

                    <div class="student-grid">
                        <?php foreach ($students as $student): ?>
                            <?php echo AcademicDirectory::render_template('partials/student-card.php', array('student' => $student, 'member_section' => $member_section, 'class_name' => $class_name)); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
