/**
 * processing_form.js — Client-side interactivity for processing_configure.php.
 *
 * Three rules, all mirroring server-side logic so a submission behaves the
 * same whether the user changed values or not:
 *
 *   1. visible_when — a field carrying `data-visible-when-field=X` and
 *      `data-visible-when-equals=V` is shown iff the sibling X currently
 *      equals V (compared as strings).
 *
 *   2. disables — a section carrying `data-disables=[{when:{field,equals},
 *      stages:[key,...]}]` hides each named target section whenever the
 *      trigger fires. Hidden (not merely disabled) so users don't see
 *      settings that won't affect their job.
 *
 *   3. conditional default — a field carrying `data-conditional-default=
 *      {field, cases}` updates its own value to `cases[sibling]` whenever
 *      the sibling in the same section changes. Fires on every change
 *      (does not track "user modified" state).
 *
 * The script runs once on DOMContentLoaded, wires up change/input listeners,
 * and then runs a startup pass so initial state is consistent.
 */

(function () {
    'use strict';

    var form = document.getElementById('processing-form');
    if (!form) return;

    // ── Value read ────────────────────────────────────────────────────────
    function fieldValue(sectionKey, fieldName) {
        var container = form.querySelector(
            '[data-section="' + sectionKey + '"][data-field="' + fieldName + '"]'
        );
        if (!container) return null;
        var input = container.querySelector('input, select');
        if (!input) return null;
        if (input.type === 'checkbox') {
            return input.checked ? '1' : '0';
        }
        return String(input.value);
    }

    // ── visible_when ──────────────────────────────────────────────────────
    function updateVisibility() {
        form.querySelectorAll('[data-visible-when-field]').forEach(function (el) {
            var sectionKey  = el.getAttribute('data-section');
            var siblingName = el.getAttribute('data-visible-when-field');
            var expected    = el.getAttribute('data-visible-when-equals');
            var actual      = fieldValue(sectionKey, siblingName);
            el.style.display = (actual !== null && actual === expected) ? '' : 'none';
        });
    }

    // ── disables: hide target sections entirely ───────────────────────────
    function updateSectionVisibility() {
        // Clear the "programmatically hidden" flag from the previous run so
        // sections re-appear when the triggering rule no longer fires.
        form.querySelectorAll('[data-hidden-by-rule="1"]').forEach(function (el) {
            el.removeAttribute('data-hidden-by-rule');
            el.style.display = '';
        });

        form.querySelectorAll('[data-disables]').forEach(function (sec) {
            var rules;
            try {
                rules = JSON.parse(sec.getAttribute('data-disables'));
            } catch (e) {
                return;
            }
            if (!Array.isArray(rules)) return;
            var sectionKey = sec.getAttribute('data-section');

            rules.forEach(function (rule) {
                if (!rule || !rule.when) return;
                var actual = fieldValue(sectionKey, rule.when.field);
                if (actual === null || actual !== String(rule.when.equals)) return;

                (rule.stages || []).forEach(function (targetKey) {
                    var target = form.querySelector(
                        '.pp-panel[data-section="' + targetKey + '"]'
                    );
                    if (!target) return;
                    target.setAttribute('data-hidden-by-rule', '1');
                    target.style.display = 'none';
                });
            });
        });
    }

    // ── conditional defaults: sibling-driven values ───────────────────────
    //
    // Reads `data-conditional-default` on a field wrapper, of the shape
    //   {"field":"method","cases":{"CAMPBELL":"PEAK_SNR","CONTRAST":"L4"}}
    // and, whenever the sibling changes, snaps the wrapper's input to the
    // matching case value. Overrides any prior user choice — the sibling
    // change is treated as a context switch.
    function applyConditionalDefaults() {
        form.querySelectorAll('[data-conditional-default]').forEach(function (el) {
            var rules;
            try {
                rules = JSON.parse(el.getAttribute('data-conditional-default'));
            } catch (e) {
                return;
            }
            if (!rules || !rules.field || !rules.cases) return;

            var sectionKey  = el.getAttribute('data-section');
            var siblingVal  = fieldValue(sectionKey, rules.field);
            if (siblingVal === null) return;
            var newVal = rules.cases[siblingVal];
            if (newVal === undefined) return;

            var input = el.querySelector('input, select');
            if (!input) return;
            if (String(input.value) !== String(newVal)) {
                input.value = String(newVal);
            }
        });
    }

    function updateAll() {
        applyConditionalDefaults();
        updateVisibility();
        updateSectionVisibility();
    }

    form.addEventListener('change', updateAll);
    form.addEventListener('input',  updateAll);
    updateAll();
})();
