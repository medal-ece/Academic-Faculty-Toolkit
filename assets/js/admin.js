(function($) {
    'use strict';

    $(function() {
        var eduIndex = parseInt($('.student-education-table tbody').data('next-index'), 10) || 0;

        function clearEduForm() {
            $('.education-edit-form').find('input[type=text],input[type=url]').val('');
            $('.education-edit-form').find('select').val('');
            $('#education-edit-index').val('');
            $('.education-form-title').text('Add Education');
        }

        function setHidden(row, field, value) {
            row.find('input[name$="[' + field + ']"]').val(value);
        }

        function updatePiLinkOrder() {
            $('.academic-pi-link-order').each(function() {
                var keys = $(this).find('[data-link-key]').map(function() {
                    return $(this).data('link-key');
                }).get();
                $($(this).data('order-target')).val(keys.join(','));
            });
        }

        function updateMediaPreview(input) {
            var field = $(input);
            var value = $.trim(field.val() || '');
            var preview = field.closest('.media-field').next('.academic-media-preview');

            if (!preview.length) {
                preview = $('<div class="academic-media-preview" aria-hidden="true"><img alt=""></div>');
                field.closest('.media-field').after(preview);
            }

            if (value && /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(value)) {
                preview.find('img').attr('src', value);
                preview.show();
            } else {
                preview.hide();
            }
        }

        $('.academic-pi-link-order').sortable({
            handle: '.academic-sort-handle',
            placeholder: 'academic-sort-placeholder',
            forcePlaceholderSize: true,
            update: updatePiLinkOrder
        });
        updatePiLinkOrder();

        $('.media-field input').each(function() {
            updateMediaPreview(this);
        });

        $('.academic-directory-admin').on('click', '.academic-media-select', function(e) {
            e.preventDefault();

            var button = $(this);
            var input = $(button.data('target'));
            var frame = wp.media({
                title: 'Select or upload image',
                button: {
                    text: 'Use this image'
                },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                input.val(attachment.url || attachment.filename);
                input.trigger('change');
            });

            frame.open();
        });

        $('.academic-directory-admin').on('input change', '.media-field input', function() {
            updateMediaPreview(this);
        });

        $('.academic-directory-admin').on('click', '.academic-new-education', function(e) {
            e.preventDefault();
            clearEduForm();
        });

        $('.academic-directory-admin').on('click', '.academic-save-education', function(e) {
            e.preventDefault();

            var editIndex = $('#education-edit-index').val();
            var index = editIndex !== '' ? editIndex : eduIndex++;
            var title = $('#education-form-title').val();
            var institution = $('#education-form-institution').val();
            var universityLink = $('#education-form-university-link').val();
            var start = $('#education-form-start').val();
            var end = $('#education-form-end').val();
            var row = $('.student-education-table tbody tr[data-education-index="' + index + '"]');

            if (!row.length) {
                row = $('<tr></tr>')
                    .attr('data-education-index', index)
                    .appendTo('.student-education-table tbody');
            }

            row.empty();
            $('<td></td>').text(title).appendTo(row);
            $('<td></td>').text(institution).appendTo(row);
            $('<td></td>').text(start).appendTo(row);
            $('<td></td>').text(end || 'Present').appendTo(row);

            var actionCell = $('<td></td>').appendTo(row);
            $('<button type="button" class="button academic-edit-education">Edit</button>').appendTo(actionCell);
            actionCell.append(' ');
            $('<button type="button" class="button academic-remove-education">Remove</button>').appendTo(actionCell);

            ['education_title', 'institution', 'university_link', 'start_date', 'end_date'].forEach(function(field) {
                $('<input type="hidden">')
                    .attr('name', 'education[' + index + '][' + field + ']')
                    .appendTo(actionCell);
            });

            setHidden(row, 'education_title', title);
            setHidden(row, 'institution', institution);
            setHidden(row, 'university_link', universityLink);
            setHidden(row, 'start_date', start);
            setHidden(row, 'end_date', end);

            clearEduForm();
        });

        $('.academic-directory-admin').on('click', '.academic-edit-education', function(e) {
            e.preventDefault();

            var row = $(this).closest('tr');
            $('#education-edit-index').val(row.data('education-index'));
            $('#education-form-title').val(row.find('input[name$="[education_title]"]').val());
            $('#education-form-institution').val(row.find('input[name$="[institution]"]').val());
            $('#education-form-university-link').val(row.find('input[name$="[university_link]"]').val());
            $('#education-form-start').val(row.find('input[name$="[start_date]"]').val());
            $('#education-form-end').val(row.find('input[name$="[end_date]"]').val());
            $('.education-form-title').text('Edit Education');
        });

        $('.academic-directory-admin').on('click', '.academic-remove-education', function(e) {
            e.preventDefault();
            $(this).closest('tr').remove();
        });

        $('.academic-directory-admin').on('click', '.academic-add-option-row', function(e) {
            e.preventDefault();

            var table = $($(this).data('target'));
            var tbody = table.find('tbody');
            var group = table.data('group');
            var type = table.data('type');
            var index = parseInt(tbody.data('next-index'), 10) || 0;
            var row = $('<tr></tr>');

            tbody.data('next-index', index + 1);

            if (type === 'label') {
                $('<td></td>').append($('<input type="text">').attr('name', group + '[' + index + '][value]')).appendTo(row);
                $('<td></td>').append($('<input type="text">').attr('name', group + '[' + index + '][label]')).appendTo(row);
            } else {
                $('<td></td>').append($('<input type="text">').attr('name', group + '[' + index + '][value]')).appendTo(row);
                $('<td></td>').append(
                    $('<input class="rank-input" type="number" min="1" step="1">')
                        .attr('name', group + '[' + index + '][rank]')
                        .val(index + 1)
                ).appendTo(row);
            }

            $('<td></td>').append($('<button type="button" class="button academic-remove-option-row">Remove</button>')).appendTo(row);
            tbody.append(row);
        });

        $('.academic-directory-admin').on('click', '.academic-remove-option-row', function(e) {
            e.preventDefault();
            $(this).closest('tr').remove();
        });
    });
})(jQuery);
