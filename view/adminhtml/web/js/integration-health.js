/*global FORM_KEY*/
define([
    'jquery',
    'jquery/ui',
    'mage/backend/validation'
], function ($) {
    'use strict';

    $.widget('watchtower.watchtowerIntegrationHealth', {
        options: {
            saveUrl: '',
            deleteUrl: '',
            cronJobsUrl: '',
            queueTopicsUrl: ''
        },

        /** @inheritdoc */
        _create: function () {
            this._loadIdentifierOptions(this.options.cronJobsUrl, 'cron_job', 'jobCodes');
            this._loadIdentifierOptions(this.options.queueTopicsUrl, 'queue_consumer', 'topics');

            this.element.on('change', '[data-role="source-type"]', function (event) {
                this._toggleIdentifierControls($(event.target).closest('[data-store-view-row]'));
            }.bind(this));

            this.element.on('click', '[data-role="save"]', function (event) {
                this._save($(event.target).closest('[data-store-view-row]'));
            }.bind(this));

            this.element.on('click', '[data-role="clear"]', function (event) {
                this._clear($(event.target).closest('[data-store-view-row]'));
            }.bind(this));

            // jQuery Validation's rules() reader refuses to read even a
            // class-based rule (e.g. validate-select) unless the field's
            // native element.form resolves to a <form> that itself has a
            // bound Validator instance -- metadataRules() unconditionally
            // dereferences $.data(element.form, 'validator').settings, wrapper
            // <form> or not. This bind is never used to drive validation
            // itself (each row's own $row.validation() below does that); it
            // exists purely so that lookup does not throw.
            this.element.find('form').validate();

            this.element.find('[data-store-view-row]').each(function (index, row) {
                var $row = $(row);

                this._toggleIdentifierControls($row);

                // Bound per row, not once on the whole form: each row saves
                // independently, so validating one row must never surface
                // errors for a different, untouched row. mage.validation
                // works on any container scoped inside the wrapper <form>,
                // not just the <form> itself.
                $row.validation();
            }.bind(this));
        },

        /**
         * Fetches one source kind's selectable identifiers once and fills
         * every row's dropdown for that kind, rather than one request per
         * row.
         * @param {String} url
         * @param {String} sourceType
         * @param {String} responseKey
         * @private
         */
        _loadIdentifierOptions: function (url, sourceType, responseKey) {
            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'json',
                showLoader: true
            }).done(function (response) {
                var values = response[responseKey] || [];

                this.element.find('[data-source-identifier-for="' + sourceType + '"]').each(function (index, select) {
                    var $select = $(select),
                        selected = $select.attr('data-selected') || '';

                    values.forEach(function (value) {
                        $('<option>').attr('value', value).text(value).appendTo($select);
                    });

                    // The configured value may no longer be in the fetched
                    // list (cron_schedule purges hourly, so a daily job's
                    // own code is often absent) -- without this, a genuinely
                    // configured row would silently render as
                    // "-- Not configured --" on every page load. Caught in
                    // C4's own architect review. A .filter() with a strict
                    // comparison, not a concatenated attribute selector: the
                    // value is admin-entered and must not be interpolated
                    // into CSS-selector syntax.
                    var alreadyPresent = $select.find('option').filter(function (i, option) {
                        return option.value === selected;
                    }).length > 0;

                    if (selected !== '' && !alreadyPresent) {
                        $('<option>').attr('value', selected).text(selected).appendTo($select);
                    }

                    $select.val(selected);
                });
            }.bind(this));
        },

        /**
         * Shows only the source_identifier control matching the row's
         * currently-selected source type, and disables the other two.
         * Disabling (not just hiding) matters: mage.validation's admin
         * ignore list excludes :disabled elements, so the two inactive
         * controls are never validated or submitted as the identifier.
         * @param {jQuery} $row
         * @private
         */
        _toggleIdentifierControls: function ($row) {
            var sourceType = $row.find('[data-role="source-type"]').val();

            $row.find('[data-source-identifier-for]').each(function (index, control) {
                var $control = $(control),
                    isActive = $control.attr('data-source-identifier-for') === sourceType;

                $control.toggle(isActive).prop('disabled', !isActive);
            });
        },

        /**
         * The control holding this row's source_identifier for whichever
         * source type is currently selected.
         * @param {jQuery} $row
         * @returns {jQuery}
         * @private
         */
        _activeIdentifierControl: function ($row) {
            var sourceType = $row.find('[data-role="source-type"]').val();

            return $row.find('[data-source-identifier-for="' + sourceType + '"]');
        },

        /**
         * @param {jQuery} $row
         * @private
         */
        _save: function ($row) {
            var $messages = $row.find('[data-role="messages"]');

            // Client-side check first, using the same required/select/length/
            // range rules etc/adminhtml/system.xml's own fields use -- catches
            // an empty or out-of-range value before a round trip, without
            // replacing IntegrationHealthConfigValidator's own server-side
            // check, which still runs regardless.
            //
            // Deliberately $row.validate().form(), not the widget's own
            // isValid()/$.fn.valid() shortcut: that shortcut assumes its
            // target is either a real <form> or a single field with a
            // .form property, and throws on a plain container holding
            // several fields (this row). .validate() just returns the
            // validator already bound in _create() (idempotent), and
            // .form() is the same "validate everything, show errors"
            // codepath a real <form> submit would run.
            if (!$row.validate().form()) {
                return;
            }

            $.ajax({
                url: this.options.saveUrl,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'store_view_id': $row.attr('data-store-view-row'),
                    'source_type': $row.find('[data-role="source-type"]').val(),
                    'source_identifier': this._activeIdentifierControl($row).val() || '',
                    'expected_max_interval_minutes': $row.find('[data-role="expected-max-interval"]').val()
                }
            }).done(function (response) {
                if (response.success) {
                    this._render($messages, 'success', [$.mage.__('Saved.')]);
                    $row.find('[data-role="clear"]').show();

                    return;
                }

                this._render($messages, 'error', response.errors || [$.mage.__('Save failed.')]);
            }.bind(this)).fail(function () {
                this._render($messages, 'error', [$.mage.__('Request failed.')]);
            }.bind(this));
        },

        /**
         * @param {jQuery} $row
         * @private
         */
        _clear: function ($row) {
            var $messages = $row.find('[data-role="messages"]');

            $.ajax({
                url: this.options.deleteUrl,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY,
                    'store_view_id': $row.attr('data-store-view-row')
                }
            }).done(function () {
                $row.find('[data-role="source-type"]').val('');
                $row.find('[data-source-identifier-for]').val('');
                $row.find('[data-role="expected-max-interval"]').val('');
                $row.find('[data-role="clear"]').hide();
                this._toggleIdentifierControls($row);
                $row.validation('clearError');
                this._render($messages, 'success', [$.mage.__('Cleared.')]);
            }.bind(this)).fail(function () {
                this._render($messages, 'error', [$.mage.__('Request failed.')]);
            }.bind(this));
        },

        /**
         * Builds the message DOM directly and sets every value via .text(),
         * deliberately identical for the success and error paths -- server
         * error strings interpolate submitted values, so neither path may
         * ever take an .html() shortcut.
         * @param {jQuery} $messages
         * @param {String} type
         * @param {Array} lines
         * @private
         */
        _render: function ($messages, type, lines) {
            var container = $('<div>', {'class': 'message message-' + type + ' ' + type});

            lines.forEach(function (line) {
                $('<div>').text(line).appendTo(container);
            });

            $messages.empty().append(container);
        }
    });

    return $.watchtower.watchtowerIntegrationHealth;
});
