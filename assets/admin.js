/* global jQuery, cf7gfm */
(function ($) {
    'use strict';

    const s = cf7gfm.strings;

    let allForms = [];
    let entryCounts = {};
    let hasCF7DB = false;

    // ══════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════
    $(function () {
        loadEntryCountsThenForms();
        bindEvents();
    });

    // Load CF7DB entry counts first, then render table
    function loadEntryCountsThenForms() {
        $.post(cf7gfm.ajaxurl, { action: 'cf7gfm_get_entry_counts', nonce: cf7gfm.nonce })
            .done(function (res) {
                if (res.success) { hasCF7DB = res.data.has_cf7db; entryCounts = res.data.counts || {}; }
            })
            .always(loadForms);
    }

    // ══════════════════════════════════════════════════
    // Load forms list
    // ══════════════════════════════════════════════════
    function loadForms() {
        $('#cf7gfm-loading').show().html('<span class="spinner is-active"></span> ' + s.loading);
        $('#cf7gfm-table').hide();

        $.post(cf7gfm.ajaxurl, { action: 'cf7gfm_get_forms', nonce: cf7gfm.nonce })
            .done(function (res) {
                if (!res.success) { showError(res.data.message || 'Could not load forms.'); return; }
                allForms = res.data;
                renderTable(allForms);
            })
            .fail(function () { showError('Connection error. Please try again.'); })
            .always(function () { $('#cf7gfm-loading').hide(); });
    }

    // ══════════════════════════════════════════════════
    // Render table
    // ══════════════════════════════════════════════════
    function renderTable(forms) {
        if (!forms || forms.length === 0) {
            $('#cf7gfm-loading').show().html('<p>' + s.no_forms + '</p>');
            return;
        }
        const tbody = $('#cf7gfm-tbody');
        tbody.empty();
        forms.forEach(form => tbody.append(buildRow(form)));
        $('#cf7gfm-table').show();
        updateSelectedCount();
    }

    function buildRow(form) {
        const migrated = form.migrated;

        // Field type badges
        let fieldTags = '';
        if (form.fields && form.fields.length) {
            form.fields.slice(0, 8).forEach(f => {
                const req = f.required ? ' cf7gfm-field-tag--required' : '';
                fieldTags += '<span class="cf7gfm-field-tag' + req + '">' + escHtml(f.type) + (f.required ? '*' : '') + '</span>';
            });
            if (form.fields.length > 8) fieldTags += '<span class="cf7gfm-field-tag">+' + (form.fields.length - 8) + '</span>';
        } else {
            fieldTags = '<span style="color:#9ca3af;font-size:12px;">—</span>';
        }

        // Status
        const statusHtml = migrated
            ? '<span class="cf7gfm-status cf7gfm-status--migrated" id="status-' + form.cf7_id + '">✅ Migrated</span>'
            : '<span class="cf7gfm-status cf7gfm-status--pending" id="status-' + form.cf7_id + '">⏳ Pending</span>';

        // GF info
        let gfInfo = '—';
        if (migrated && form.gf_id) {
            const shortTitle = (form.gf_title || '').length > 35
                ? (form.gf_title || '').substring(0, 35) + '…'
                : (form.gf_title || '');
            gfInfo = '<div><strong>ID:</strong> ' + form.gf_id + '</div>'
                + '<div class="cf7gfm-gf-title" title="' + escHtml(form.gf_title || '') + '">' + escHtml(shortTitle) + '</div>';
        }

        // Entry column
        const count = entryCounts[form.title] || entryCounts[form.post_name] || 0;
        const entryCol = buildEntryColumn(form, count);

        // Actions
        let actions = '';
        if (migrated && form.gf_edit_url) {
            actions = '<a href="' + escHtml(form.gf_edit_url) + '" target="_blank" class="button cf7gfm-btn cf7gfm-btn--secondary cf7gfm-btn--sm">🔗 ' + s.view_gf + '</a>';
            actions += ' <button class="button cf7gfm-btn cf7gfm-btn--ghost cf7gfm-btn--sm cf7gfm-single-migrate" data-id="' + form.cf7_id + '">🔄 ' + s.remigrate + '</button>';
        } else {
            actions = '<button class="button button-primary cf7gfm-btn cf7gfm-btn--primary cf7gfm-btn--sm cf7gfm-single-migrate" data-id="' + form.cf7_id + '">→ ' + s.migrate + '</button>';
        }

        const usedOnCol = buildUsedOnColumn(form.used_on);

        return '<tr class="' + (migrated ? 'is-migrated' : '') + '" id="row-' + form.cf7_id + '">'
            + '<td><input type="checkbox" class="cf7gfm-check" value="' + form.cf7_id + '"></td>'
            + '<td><span class="cf7gfm-id">' + form.cf7_id + '</span></td>'
            + '<td><div class="cf7gfm-form-title">' + escHtml(form.title) + '</div></td>'
            + '<td><span class="cf7gfm-field-count">' + form.field_count + '</span></td>'
            + '<td><div class="cf7gfm-fields">' + fieldTags + '</div></td>'
            + '<td id="status-wrap-' + form.cf7_id + '">' + statusHtml + '</td>'
            + '<td id="gf-info-' + form.cf7_id + '">' + gfInfo + '</td>'
            + '<td class="cf7gfm-used-on">' + usedOnCol + '</td>'
            + '<td id="entry-col-' + form.cf7_id + '">' + entryCol + '</td>'
            + '<td class="cf7gfm-actions" id="actions-' + form.cf7_id + '">' + actions + '</td>'
            + '</tr>';
    }

    function buildEntryColumn(form, entryCount) {
        if (!hasCF7DB) {
            return '<span style="color:#9ca3af;font-size:12px;">' + s.no_cf7db + '</span>';
        }
        if (!entryCount) {
            return '<span style="color:#9ca3af;font-size:12px;">' + s.no_entries + '</span>';
        }

        let html = '<div style="font-size:12px;margin-bottom:5px;"><strong>' + entryCount + '</strong> entries in CF7DB</div>';

        if (form.migrated && form.gf_id) {
            html += '<button class="button cf7gfm-btn cf7gfm-btn--primary cf7gfm-btn--sm cf7gfm-migrate-entries" '
                + 'data-cf7id="' + form.cf7_id + '" data-gfid="' + form.gf_id + '" '
                + 'data-formtitle="' + escHtml(form.title) + '">📥 ' + s.migrate_entries + '</button>';
        } else {
            html += '<span style="color:#9ca3af;font-size:11px;">Migrate form first</span>';
        }
        return html;
    }

    /**
     * Build the "Used On" cell showing pages/posts that embed this CF7 form.
     */
    function buildUsedOnColumn(usedOn) {
        if (!usedOn || usedOn.length === 0) {
            return '<span style="color:#9ca3af;font-size:12px;">Not used anywhere</span>';
        }
        let html = '<ul class="cf7gfm-used-on-list">';
        usedOn.forEach(function (page) {
            const dot = page.status === 'publish' ? '🟢' : (page.status === 'draft' ? '🟡' : '⚪');
            const type = (page.type !== 'page' && page.type !== 'post') ? ' <em>(' + escHtml(page.type) + ')</em>' : '';
            html += '<li>' + dot + ' '
                + '<a href="' + escHtml(page.url) + '" target="_blank">' + escHtml(page.title) + '</a>'
                + type
                + ' <a href="' + escHtml(page.edit_url) + '" target="_blank" class="cf7gfm-edit-link" title="Edit">✏️</a>'
                + '</li>';
        });
        html += '</ul>';
        return html;
    }

    // ══════════════════════════════════════════════════
    // Event bindings
    // ══════════════════════════════════════════════════
    function bindEvents() {
        $('#cf7gfm-refresh').on('click', function () {
            $(this).find('.dashicons').addClass('spin');
            loadEntryCountsThenForms();
            setTimeout(() => $('#cf7gfm-refresh .dashicons').removeClass('spin'), 800);
        });

        $('#cf7gfm-check-header, #cf7gfm-select-all').on('change', function () {
            const checked = $(this).prop('checked');
            $('#cf7gfm-table .cf7gfm-check').prop('checked', checked);
            updateSelectedCount();
        });

        $(document).on('change', '.cf7gfm-check', updateSelectedCount);

        $('#cf7gfm-migrate-selected').on('click', function () {
            const ids = getSelectedIds();
            if (!ids.length) { alert(s.select_first); return; }
            if (!confirm(s.confirm_selected)) return;
            migrateMultiple(ids);
        });

        $('#cf7gfm-migrate-all').on('click', function () {
            const unmigrated = allForms.filter(f => !f.migrated).map(f => f.cf7_id);
            if (!unmigrated.length) { alert('All forms have already been migrated!'); return; }
            if (!confirm(s.confirm_all)) return;
            migrateMultiple(unmigrated);
        });

        $(document).on('click', '.cf7gfm-single-migrate', function () {
            migrateSingle($(this).data('id'));
        });

        $(document).on('click', '.cf7gfm-migrate-entries', function () {
            const $btn = $(this);
            const cf7Id = $btn.data('cf7id');
            const gfId = $btn.data('gfid');
            const formTitle = $btn.data('formtitle');

            if (!confirm(s.confirm_entries + '\n\nForm: ' + formTitle)) return;
            $btn.prop('disabled', true).text('⏳ ' + s.migrating_entries);

            $.post(cf7gfm.ajaxurl, {
                action: 'cf7gfm_migrate_entries',
                nonce: cf7gfm.nonce,
                cf7_id: cf7Id,
                gf_id: gfId,
                form_name: formTitle,
                re_migrate: 0,
            })
                .done(function (res) {
                    if (res.success) {
                        setEntryMigrated(cf7Id, gfId, res.data);
                    } else {
                        $btn.prop('disabled', false).text('📥 ' + s.migrate_entries);
                        $('#entry-col-' + cf7Id).append('<div style="color:#b91c1c;font-size:11px;margin-top:4px;">❌ ' + escHtml(res.data.message) + '</div>');
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false).text('📥 ' + s.migrate_entries);
                    $('#entry-col-' + cf7Id).append('<div style="color:#b91c1c;font-size:11px;margin-top:4px;">❌ Connection error.</div>');
                });
        });
    }

    // ══════════════════════════════════════════════════
    // Migrate single form
    // ══════════════════════════════════════════════════
    function migrateSingle(cf7Id) {
        setRowMigrating(cf7Id);
        $.post(cf7gfm.ajaxurl, { action: 'cf7gfm_migrate_form', nonce: cf7gfm.nonce, cf7_id: cf7Id })
            .done(function (res) {
                if (res.success) {
                    setRowMigrated(cf7Id, res.data.gf_id, res.data.gf_title, res.data.edit_url);
                    const form = allForms.find(f => f.cf7_id === cf7Id);
                    if (form) {
                        form.migrated = true; form.gf_id = res.data.gf_id;
                        form.gf_title = res.data.gf_title; form.gf_edit_url = res.data.edit_url;
                        // Refresh entry column now GF form exists
                        const count = entryCounts[form.title] || entryCounts[form.post_name] || 0;
                        $('#entry-col-' + cf7Id).html(buildEntryColumn(form, count));
                    }
                } else { setRowError(cf7Id, res.data.message); }
            })
            .fail(function () { setRowError(cf7Id, 'Server connection error.'); });
    }

    // ══════════════════════════════════════════════════
    // Migrate multiple forms (sequential with progress)
    // ══════════════════════════════════════════════════
    function migrateMultiple(ids) {
        let done = 0, successCount = 0, errorCount = 0;
        const errors = [], total = ids.length;

        showProgress(0, total);
        hideResult();

        function next(index) {
            if (index >= ids.length) {
                hideProgress();
                showFinalResult(successCount, errorCount, errors);
                loadEntryCountsThenForms();
                return;
            }
            const cf7Id = ids[index];
            setRowMigrating(cf7Id);
            $.post(cf7gfm.ajaxurl, { action: 'cf7gfm_migrate_form', nonce: cf7gfm.nonce, cf7_id: cf7Id })
                .done(function (res) {
                    done++;
                    if (res.success) { successCount++; setRowMigrated(cf7Id, res.data.gf_id, res.data.gf_title, res.data.edit_url); }
                    else { errorCount++; errors.push({ id: cf7Id, msg: res.data.message }); setRowError(cf7Id, res.data.message); }
                    updateProgress(done, total);
                    next(index + 1);
                })
                .fail(function () {
                    done++; errorCount++;
                    errors.push({ id: cf7Id, msg: 'Connection error' });
                    setRowError(cf7Id, 'Connection error');
                    updateProgress(done, total);
                    next(index + 1);
                });
        }
        next(0);
    }

    // ══════════════════════════════════════════════════
    // Row state helpers
    // ══════════════════════════════════════════════════
    function setRowMigrating(id) {
        $('#row-' + id).removeClass('is-migrated').addClass('is-migrating');
        $('#status-wrap-' + id).html('<span class="cf7gfm-status cf7gfm-status--migrating">🔄 Migrating…</span>');
        $('#actions-' + id).html('<span style="color:#6366f1;font-size:12px;">Processing…</span>');
        $('#gf-info-' + id).html('—');
    }

    function setRowMigrated(id, gfId, gfTitle, editUrl) {
        $('#row-' + id).removeClass('is-migrating').addClass('is-row-migrated');
        $('#status-wrap-' + id).html('<span class="cf7gfm-status cf7gfm-status--migrated">✅ Migrated</span>');
        const short = (gfTitle || '').length > 35 ? (gfTitle || '').substring(0, 35) + '…' : (gfTitle || '');
        $('#gf-info-' + id).html('<div><strong>ID:</strong> ' + gfId + '</div><div class="cf7gfm-gf-title">' + escHtml(short) + '</div>');
        $('#actions-' + id).html(
            '<a href="' + escHtml(editUrl) + '" target="_blank" class="button cf7gfm-btn cf7gfm-btn--secondary cf7gfm-btn--sm">🔗 ' + s.view_gf + '</a>'
            + ' <button class="button cf7gfm-btn cf7gfm-btn--ghost cf7gfm-btn--sm cf7gfm-single-migrate" data-id="' + id + '">🔄 ' + s.remigrate + '</button>'
        );
    }

    function setRowError(id, msg) {
        $('#row-' + id).removeClass('is-migrating');
        $('#status-wrap-' + id).html('<span class="cf7gfm-status cf7gfm-status--error">❌ Error</span>');
        $('#actions-' + id).html(
            '<span style="color:#b91c1c;font-size:12px;">' + escHtml(msg) + '</span>'
            + ' <button class="button cf7gfm-btn cf7gfm-btn--ghost cf7gfm-btn--sm cf7gfm-single-migrate" data-id="' + id + '">🔄 Retry</button>'
        );
    }

    function setEntryMigrated(cf7Id, gfId, data) {
        let html = '<div style="font-size:12px;margin-bottom:4px;">✅ <strong>' + data.migrated + '</strong> ' + s.entries_migrated;
        if (data.skipped > 0) html += ', ' + data.skipped + ' ' + s.skipped;
        if (data.errors && data.errors.length) html += ', <span style="color:#ef4444">' + data.errors.length + ' ' + s.errors + '</span>';
        html += '</div>';
        if (data.entries_url) {
            html += '<a href="' + escHtml(data.entries_url) + '" target="_blank" class="button cf7gfm-btn cf7gfm-btn--secondary cf7gfm-btn--sm">📋 ' + s.view_entries + '</a>';
        }
        const form = allForms.find(f => f.cf7_id === cf7Id);
        html += ' <button class="button cf7gfm-btn cf7gfm-btn--ghost cf7gfm-btn--sm cf7gfm-migrate-entries" '
            + 'data-cf7id="' + cf7Id + '" data-gfid="' + gfId + '" data-formtitle="' + escHtml((form || {}).title || '') + '">'
            + '🔄 ' + s.remigrate_entries + '</button>';
        $('#entry-col-' + cf7Id).html(html);
    }

    // ══════════════════════════════════════════════════
    // Progress bar
    // ══════════════════════════════════════════════════
    function showProgress(done, total) {
        $('#cf7gfm-progress-wrap').show();
        updateProgress(done, total);
    }

    function updateProgress(done, total) {
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#cf7gfm-progress-fill').css('width', pct + '%');
        $('#cf7gfm-progress-text').text(done + ' / ' + total + ' forms (' + pct + '%)');
    }

    function hideProgress() {
        setTimeout(() => $('#cf7gfm-progress-wrap').fadeOut(400), 600);
    }

    // ══════════════════════════════════════════════════
    // Result summary
    // ══════════════════════════════════════════════════
    function showFinalResult(successCount, errorCount, errors) {
        const $result = $('#cf7gfm-result');
        let html = '';
        if (successCount > 0) {
            html += '<div class="cf7gfm-result cf7gfm-result--success"><h3>✅ Successfully migrated ' + successCount + ' form' + (successCount > 1 ? 's' : '') + '.</h3></div>';
        }
        if (errorCount > 0) {
            html += '<div class="cf7gfm-result cf7gfm-result--error"><h3>❌ ' + errorCount + ' form(s) failed:</h3><ul>';
            errors.forEach(e => { html += '<li>CF7 Form #' + e.id + ': ' + escHtml(e.msg) + '</li>'; });
            html += '</ul></div>';
        }
        $result.html(html).show();
        $('html, body').animate({ scrollTop: $result.offset().top - 60 }, 300);
    }

    function hideResult() { $('#cf7gfm-result').hide().html(''); }

    function showError(msg) {
        $('#cf7gfm-result').html('<div class="cf7gfm-result cf7gfm-result--error"><h3>❌ ' + escHtml(msg) + '</h3></div>').show();
    }

    // ══════════════════════════════════════════════════
    // Utilities
    // ══════════════════════════════════════════════════
    function getSelectedIds() {
        return $('.cf7gfm-check:checked').map(function () { return parseInt($(this).val(), 10); }).get();
    }

    function updateSelectedCount() {
        const count = $('.cf7gfm-check:checked').length;
        $('#cf7gfm-selected-count').text(count + ' form' + (count !== 1 ? 's' : '') + ' selected');
        $('#cf7gfm-migrate-selected').prop('disabled', count === 0);
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

}(jQuery));
