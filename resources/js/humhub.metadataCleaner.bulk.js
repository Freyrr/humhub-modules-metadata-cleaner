/**
 * "Existing files" tab of the Metadata Cleaner settings: starts, pauses,
 * resumes and stops the cleaning job and drives it batch by batch while
 * the page is open.
 *
 * @license AGPL-3.0-or-later
 */
humhub.module('metadataCleaner.bulk', function (module, require, $) {
    var client = require('client');
    var modal = require('ui.modal');

    var state = null;

    var root = function () {
        return $('#metadata-cleaner-bulk');
    };

    var render = function () {
        var cfg = module.config;
        var s = state.status;
        var active = s === 'running' || s === 'paused' || s === 'done';
        $('#metadata-cleaner-bulk-start').prop('disabled', s === 'running' || s === 'paused').toggle(s === 'idle' || s === 'done');
        $('#metadata-cleaner-bulk-pause').toggle(s === 'running');
        $('#metadata-cleaner-bulk-resume').toggle(s === 'paused');
        $('#metadata-cleaner-bulk-stop').toggle(s === 'running' || s === 'paused' || s === 'done');
        $('#metadata-cleaner-bulk-dryrun').prop('disabled', s === 'running' || s === 'paused');
        $('#metadata-cleaner-bulk-progress').toggle(active);

        var label = (cfg.labels[s] || s) + (state.dryRun && s !== 'idle' ? ' ' + cfg.labels.dryRunSuffix : '');
        $('#metadata-cleaner-bulk-status').text(label);
        $('#metadata-cleaner-tab-bulk').text(
            cfg.tabBulkLabel + ((s === 'running' || s === 'paused') ? ' (' + label + ')' : '')
        );

        var pct = state.total > 0 ? Math.min(100, Math.round(state.processed / state.total * 100)) : 0;
        $('#metadata-cleaner-bulk-bar').css('width', pct + '%');
        $('#metadata-cleaner-bulk-processed').text(state.processed + ' / ' + state.total);
        $('#metadata-cleaner-bulk-cleaned').text(state.cleaned);
        $('#metadata-cleaner-bulk-unchanged').text(state.unchanged);
        $('#metadata-cleaner-bulk-skipped').text(state.skipped);
        $('#metadata-cleaner-bulk-failed').text(state.failed);
        $('#metadata-cleaner-bulk-current').text(
            state.currentFile ? cfg.labels.currentFile.replace('{name}', state.currentFile) : ''
        );
    };

    var control = function (op, extra) {
        return client.post(module.config.controlUrl, {data: $.extend({op: op}, extra || {})}).then(function (res) {
            state = res.state;
            render();
            return state;
        });
    };

    var step = function () {
        // Stop polling when the page was left.
        if (!root().length) {
            return;
        }
        if (state.status !== 'running') {
            render();
            return;
        }
        client.post(module.config.stepUrl, {}).then(function (res) {
            state = res.state;
            render();
            if (state.status === 'running') {
                step();
            }
        }).catch(function (e) {
            module.log.error(e, true);
            render();
        });
    };

    var start = function () {
        var dryRun = $('#metadata-cleaner-bulk-dryrun').is(':checked');
        var run = function () {
            control('start', {dryRun: dryRun ? 1 : 0}).then(step).catch(function (e) {
                module.log.error(e, true);
            });
        };
        if (dryRun) {
            run();
            return;
        }
        var text = module.config.confirm;
        modal.confirm({
            header: text.header,
            body: text.body,
            confirmText: text.confirm,
            cancelText: text.cancel
        }).then(function (confirmed) {
            if (confirmed) {
                run();
            }
        });
    };

    var init = function () {
        if (!root().length) {
            return;
        }
        state = module.config.state;
        $('#metadata-cleaner-bulk-start').on('click', start);
        $('#metadata-cleaner-bulk-pause').on('click', function () {
            control('pause');
        });
        $('#metadata-cleaner-bulk-resume').on('click', function () {
            control('resume').then(step);
        });
        $('#metadata-cleaner-bulk-stop').on('click', function () {
            control('stop');
        });

        render();
        if (state.status === 'running') {
            step();
        }
    };

    module.initOnPjaxLoad = true;

    module.export({
        init: init
    });
});
