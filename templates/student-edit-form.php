<?php

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="academic-profile-edit">
    <?php if (!$student): ?>
        <div class="academic-profile-message academic-profile-message-error">
            <h1>Profile Link Unavailable</h1>
            <p>This private edit link is invalid, expired, or has been replaced. Please contact the research group administrator for a new link.</p>
        </div>
    <?php else: ?>
        <?php
        $is_current = in_array(strtolower(trim((string) $student['active'])), array('1', 'active', 'true', 'y', 'yes'), true);
        $image_id = AcademicDirectory::get_image_id($student['image']);
        $education_rows = !empty($student['education']) ? array_values($student['education']) : array();
        ?>
        <header class="academic-profile-edit-header">
            <p class="academic-profile-edit-label"><?php echo esc_html($student['category']); ?></p>
            <h1>Edit <?php echo esc_html($student['name']); ?></h1>
            <p>Changes saved here are published to the research group profile.</p>
        </header>

        <?php if ($updated): ?>
            <div class="academic-profile-message academic-profile-message-success"><p>Your profile was updated successfully.</p></div>
        <?php elseif ($error): ?>
            <div class="academic-profile-message academic-profile-message-error"><p>Your changes could not be saved. Please try again.</p></div>
        <?php endif; ?>

        <form class="academic-profile-edit-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('academic_profile_save_public'); ?>
            <input type="hidden" name="action" value="academic_profile_save_public">
            <input type="hidden" name="profile_token" value="<?php echo esc_attr($token); ?>">
            <input class="academic-profile-honeypot" type="text" name="company" value="" tabindex="-1" autocomplete="off" aria-hidden="true">

            <section class="academic-profile-managed-section">
                <h2>Administrator-Managed Information</h2>
                <div class="academic-profile-managed-grid">
                    <?php if ($image_id): ?>
                        <div class="academic-profile-managed-photo">
                            <?php echo wp_get_attachment_image($image_id, 'thumbnail', false, array('alt' => $student['name'])); ?>
                        </div>
                    <?php endif; ?>
                    <dl>
                        <div><dt>Profile ID</dt><dd><?php echo esc_html($student['student_id']); ?></dd></div>
                        <div><dt>Researcher category</dt><dd><?php echo esc_html($student['category']); ?></dd></div>
                        <div><dt>Membership status</dt><dd><?php echo esc_html($is_current ? 'Current member' : 'Past member'); ?></dd></div>
                        <div>
                            <dt>Primary email (private record)</dt>
                            <dd>
                                <?php echo esc_html($student['email'] !== '' ? $student['email'] : 'Not specified'); ?>
                                <small class="academic-field-hint">Used only for research group records and private-link delivery. It is not displayed publicly.</small>
                            </dd>
                        </div>
                        <div><dt>Date of entry</dt><dd><?php echo esc_html($student['date_of_entry'] !== '' ? $student['date_of_entry'] : 'Not specified'); ?></dd></div>
                        <div><dt>Profile image</dt><dd><?php echo esc_html($student['image'] !== '' ? $student['image'] : 'Not selected'); ?></dd></div>
                    </dl>
                </div>
                <p class="academic-field-hint">Contact the research group administrator to change these fields or the profile image.</p>
            </section>

            <section>
                <h2>Profile</h2>
                <div class="academic-profile-form-grid">
                    <label>
                        <span>Name</span>
                        <input type="text" name="profile[name]" value="<?php echo esc_attr($student['name']); ?>" required>
                    </label>
                    <label>
                        <span>Public Email (optional)</span>
                        <input type="email" name="profile[secondary_email]" value="<?php echo esc_attr($student['secondary_email']); ?>">
                        <small class="academic-field-hint">This is the only email shown on your public card and profile. You may enter the same address as your primary email if you want that address displayed.</small>
                    </label>
                    <label>
                        <span>Pronouns</span>
                        <select name="profile[pronoun]">
                            <option value="">Prefer not to list</option>
                            <?php foreach ($pronoun_options as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($student['pronoun'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </section>

            <section>
                <h2>Profile Links</h2>
                <p class="academic-section-hint">Add any public professional links you want displayed on your profile. Leave unused fields blank.</p>
                <div class="academic-profile-form-grid">
                    <?php foreach (AcademicDirectory::get_student_link_fields() as $field => $link_settings): ?>
                        <label>
                            <span><?php echo esc_html($link_settings['label']); ?></span>
                            <input type="url" name="profile[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr(isset($student[$field]) ? $student[$field] : ''); ?>" placeholder="https://">
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section>
                <h2>Biography and Interests</h2>
                <div class="academic-field-group">
                    <span>Biography</span>
                    <?php
                    wp_editor(
                        $student['bio'],
                        'academic_student_bio_editor',
                        array(
                            'textarea_name' => 'profile[bio]',
                            'textarea_rows' => 8,
                            'media_buttons' => false,
                            'teeny' => false,
                            'quicktags' => true,
                        )
                    );
                    ?>
                    <small class="academic-field-hint">Use the toolbar to add paragraphs, links, bold text, and lists.</small>
                </div>
                <label>
                    <span>Research Interests</span>
                    <textarea name="profile[research_interests]" rows="5"><?php echo esc_textarea($student['research_interests']); ?></textarea>
                    <small class="academic-field-hint">Separate multiple research interests with semicolons (;).</small>
                </label>
                <label>
                    <span>Hobbies</span>
                    <textarea name="profile[hobbies]" rows="4"><?php echo esc_textarea($student['hobbies']); ?></textarea>
                    <small class="academic-field-hint">Optional. Use a short sentence or separate multiple hobbies with semicolons (;).</small>
                </label>
            </section>

            <section>
                <h2>Current Position</h2>
                <div class="academic-profile-form-grid">
                    <label>
                        <span>Position, employment, or current program</span>
                        <input type="text" name="profile[current_position]" value="<?php echo esc_attr($student['current_position']); ?>">
                        <small class="academic-field-hint">Especially useful for past members. Example: Design Engineer at Example Corp.</small>
                    </label>
                    <label>
                        <span>Information updated</span>
                        <input type="text" name="profile[position_updated]" value="<?php echo esc_attr($student['position_updated']); ?>" placeholder="Example: July 2026">
                    </label>
                </div>
            </section>

            <section>
                <h2>Education</h2>
                <p class="academic-section-hint">List each degree separately. Education is displayed from the highest/latest degree to the earliest degree.</p>
                <div class="academic-profile-education-editor">
                    <?php foreach ($education_rows as $index => $education): ?>
                        <?php echo AcademicDirectory::render_template('partials/student-education-edit-row.php', array(
                            'education' => $education,
                            'index' => $index,
                            'education_title_options' => $education_title_options,
                            'is_template' => false,
                        )); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="academic-add-education"><span aria-hidden="true">+</span> Add Education</button>
                <template id="academic-education-row-template">
                    <?php echo AcademicDirectory::render_template('partials/student-education-edit-row.php', array(
                        'education' => array(),
                        'index' => '__INDEX__',
                        'education_title_options' => $education_title_options,
                        'is_template' => true,
                    )); ?>
                </template>
            </section>

            <div class="academic-profile-submit">
                <button type="submit">Save Profile</button>
            </div>
        </form>
    <?php endif; ?>
</div>
