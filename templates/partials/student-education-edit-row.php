<?php

if (!defined('ABSPATH')) {
    exit;
}

$education = wp_parse_args($education, array(
    'education_title' => '',
    'institution' => '',
    'university_link' => '',
    'start_date' => '',
    'end_date' => '',
));
$is_template = !empty($is_template);
$legend = $is_template ? 'New Education' : 'Education ' . ((int) $index + 1);
?>

<fieldset class="academic-profile-education-row" data-education-index="<?php echo esc_attr($index); ?>">
    <legend><?php echo esc_html($legend); ?></legend>
    <div class="academic-profile-form-grid">
        <label>
            <span>Degree</span>
            <select name="education[<?php echo esc_attr($index); ?>][education_title]">
                <option value="">Select a degree</option>
                <?php foreach ($education_title_options as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($education['education_title'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Institution</span>
            <input type="text" name="education[<?php echo esc_attr($index); ?>][institution]" value="<?php echo esc_attr($education['institution']); ?>">
        </label>
        <label>
            <span>Institution website</span>
            <input type="url" name="education[<?php echo esc_attr($index); ?>][university_link]" value="<?php echo esc_attr($education['university_link']); ?>" placeholder="https://">
            <small class="academic-field-hint">Optional. Include the complete address beginning with https://.</small>
        </label>
        <label>
            <span>Start year</span>
            <input type="text" inputmode="numeric" name="education[<?php echo esc_attr($index); ?>][start_date]" value="<?php echo esc_attr($education['start_date']); ?>" placeholder="2022">
        </label>
        <label>
            <span>End year</span>
            <input type="text" inputmode="numeric" name="education[<?php echo esc_attr($index); ?>][end_date]" value="<?php echo esc_attr($education['end_date']); ?>" placeholder="Leave blank if current">
            <small class="academic-field-hint">Leave blank for an ongoing degree.</small>
        </label>
        <div class="academic-profile-remove-entry">
            <button type="button" class="academic-remove-education">Remove Education</button>
        </div>
    </div>
</fieldset>
