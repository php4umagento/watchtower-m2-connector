/*global FORM_KEY*/
define([
    'jquery',
    'jquery/ui'
], function ($) {
    'use strict';

    $.widget('watchtower.watchtowerTestConnection', {
        options: {
            url: '',
            elementId: ''
        },

        /** @inheritdoc */
        _create: function () {
            this._on({
                click: this._test
            });
        },

        /**
         * POST to the controller, which tests the already-saved config
         * server-side, and render the structured result.
         * @private
         */
        _test: function () {
            var resultBox = $('#' + this.options.elementId + '_result');

            resultBox.html($.mage.__('Testing...'));

            $.ajax({
                url: this.options.url,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                data: {
                    'form_key': FORM_KEY
                }
            }).done(function (response) {
                resultBox.empty().append(response.success ? this._renderSuccess(response) : this._renderError(response));
            }.bind(this)).fail(function () {
                resultBox.empty().append(this._renderError({errorMessage: $.mage.__('Request failed.')}));
            }.bind(this));
        },

        /**
         * Builds the result DOM directly and sets dynamic values via .text(),
         * the same escaping approach _renderError uses -- serverTime and
         * entitledSignals both originate from the platform's HTTP response,
         * so they're treated as untrusted rather than interpolated into an
         * HTML string.
         * @param {Object} response
         * @returns {jQuery}
         * @private
         */
        _renderSuccess: function (response) {
            var signals = response.entitledSignals && response.entitledSignals.length
                    ? response.entitledSignals.join(', ')
                    : $.mage.__('(none)'),
                skew = response.clockSkewSeconds !== null && typeof response.clockSkewSeconds !== 'undefined'
                    ? response.clockSkewSeconds + 's'
                    : $.mage.__('(unknown)'),
                container = $('<div>', {'class': 'message message-success success'}),
                addRow = function (label, value) {
                    $('<div>').text(label + ' ' + value).appendTo(container);
                };

            $('<div>').text($.mage.__('Connected.')).appendTo(container);
            addRow($.mage.__('Organization paused:'), response.organizationPaused ? $.mage.__('yes') : $.mage.__('no'));
            addRow($.mage.__('Alerting enabled:'), response.alertingEnabled ? $.mage.__('yes') : $.mage.__('no'));
            addRow($.mage.__('Entitled signals:'), signals);
            addRow($.mage.__('Server time:'), response.serverTime || $.mage.__('(unknown)'));
            addRow($.mage.__('Clock skew:'), skew);

            return container;
        },

        /**
         * @param {Object} response
         * @returns {jQuery}
         * @private
         */
        _renderError: function (response) {
            return $('<div>', {'class': 'message message-error error'})
                .text(response.errorMessage || $.mage.__('Connection failed.'));
        }
    });

    return $.watchtower.watchtowerTestConnection;
});
