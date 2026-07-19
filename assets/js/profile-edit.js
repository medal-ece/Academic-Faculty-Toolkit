(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var editor = document.querySelector('.academic-profile-education-editor');
        var addButton = document.querySelector('.academic-add-education');
        var template = document.getElementById('academic-education-row-template');

        if (!editor || !addButton || !template) {
            return;
        }

        var nextIndex = 0;
        editor.querySelectorAll('.academic-profile-education-row').forEach(function(row) {
            var index = parseInt(row.getAttribute('data-education-index'), 10);
            if (!isNaN(index)) {
                nextIndex = Math.max(nextIndex, index + 1);
            }
        });

        function renumberRows() {
            editor.querySelectorAll('.academic-profile-education-row').forEach(function(row, position) {
                var legend = row.querySelector('legend');
                if (legend) {
                    legend.textContent = 'Education ' + (position + 1);
                }
            });
        }

        function addEducation() {
            var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
            editor.insertAdjacentHTML('beforeend', html);
            renumberRows();

            var rows = editor.querySelectorAll('.academic-profile-education-row');
            var newRow = rows[rows.length - 1];
            var firstField = newRow ? newRow.querySelector('select, input') : null;
            if (firstField) {
                firstField.focus();
            }
        }

        addButton.addEventListener('click', addEducation);

        editor.addEventListener('click', function(event) {
            var removeButton = event.target.closest('.academic-remove-education');
            if (!removeButton) {
                return;
            }

            var row = removeButton.closest('.academic-profile-education-row');
            if (row) {
                row.remove();
                renumberRows();
                addButton.focus();
            }
        });

        if (!editor.querySelector('.academic-profile-education-row')) {
            addEducation();
        }
    });
})();
