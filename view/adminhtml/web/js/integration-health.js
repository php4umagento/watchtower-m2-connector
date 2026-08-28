/**
 * Copyright © 2026 Watchtower. All rights reserved.
 * Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
 */

define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    /**
     * jQuery data key holding a job checkbox's own tick state while its whole
     * integration is watched, so unticking the integration puts back exactly
     * what the merchant had picked rather than clearing it.
     */
    var OWN_STATE = 'watchtowerOwnState';

    $.widget('watchtower.watchtowerIntegrationHealth', {
        /** @inheritdoc */
        _create: function () {
            this.element.on('input', '[data-role="integration-filter"]', function (event) {
                this._filter($(event.target).val());
            }.bind(this));

            this.element.on('change', '[data-role="integration-toggle"]', function (event) {
                this._sync($(event.target).closest('[data-role="integration"]'));
            }.bind(this));

            this.element.find('[data-role="integration"]').each(function (index, integration) {
                this._sync($(integration));
            }.bind(this));
        },

        /**
         * Hides integrations that do not match what was typed.
         *
         * Client-side and immediate: the whole set is already in the page, and
         * a merchant filtering forty-two rows should not wait for a round
         * trip. Sections whose every row is hidden collapse away too, so an
         * empty "Magento's own scheduled jobs" heading is not left behind.
         *
         * @param {String} term
         */
        _filter: function (term) {
            var needle = $.trim(String(term || '')).toLowerCase(),
                anyVisible = false;

            this.element.find('[data-role="integration"]').each(function () {
                var row = $(this),
                    matches = needle === '' || (row.attr('data-search') || '').indexOf(needle) !== -1;

                row.toggle(matches);
                anyVisible = anyVisible || matches;
            });

            this.element.find('details').each(function () {
                var section = $(this),
                    rows = section.find('[data-role="integration"]');

                if (rows.length === 0) {
                    return;
                }

                section.toggle(rows.filter(':visible').length > 0);

                // A filtered section is useless collapsed, so open it while
                // filtering and leave the merchant's own state alone otherwise.
                if (needle !== '' && rows.filter(':visible').length > 0) {
                    section.prop('open', true);
                }
            });

            this.element.find('[data-role="filter-empty"]').prop('hidden', anyVisible || needle === '');
        },

        /**
         * Reflects "the whole integration is watched" onto its individual job
         * checkboxes: every job is included, so each is shown ticked and
         * disabled. Disabling is what keeps them out of the submitted set,
         * which matters because the module entry already covers them and
         * storing both would leave a stale job entry behind the day the
         * merchant unticks the module.
         * @param {jQuery} $integration
         * @private
         */
        _sync: function ($integration) {
            var watchedWhole = $integration
                .find('[data-role="integration-toggle"]')
                .prop('checked') === true;

            $integration.find('[data-role="job-toggle"]').each(function (index, job) {
                var $job = $(job);

                if (watchedWhole) {
                    if ($job.data(OWN_STATE) === undefined) {
                        $job.data(OWN_STATE, $job.prop('checked'));
                    }

                    $job.prop('checked', true).prop('disabled', true);

                    return;
                }

                if ($job.data(OWN_STATE) !== undefined) {
                    $job.prop('checked', $job.data(OWN_STATE));
                    $job.removeData(OWN_STATE);
                }

                $job.prop('disabled', false);
            });
        }
    });

    return $.watchtower.watchtowerIntegrationHealth;
});
