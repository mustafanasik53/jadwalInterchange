/**
 * Jadwal Interchange — small vanilla JS enhancements.
 * No build step, no dependencies. Progressively enhances plain HTML forms:
 * everything here still works (minus the nicety) if JS fails to load,
 * because the underlying <form>s/<a>s are real and submit normally.
 */

(function () {
  'use strict';

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // ---------------------------------------------------------------------
  // Dashboard day chips: tab switching between day panels.
  // ---------------------------------------------------------------------
  function initDayChips() {
    var chips = document.querySelectorAll('.day-chip');
    if (!chips.length) {
      return;
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        var target = chip.getAttribute('data-day-target');

        chips.forEach(function (c) {
          c.classList.remove('is-active');
          c.setAttribute('aria-selected', 'false');
        });
        chip.classList.add('is-active');
        chip.setAttribute('aria-selected', 'true');

        document.querySelectorAll('.day-panel').forEach(function (panel) {
          var isTarget = panel.getAttribute('data-day-panel') === target;
          panel.classList.toggle('is-active', isTarget);
        });
      });
    });
  }

  // ---------------------------------------------------------------------
  // Release Period modal: open with pre-filled reason, submit via fetch().
  // ---------------------------------------------------------------------
  function initReleaseModal() {
    var dialog = document.getElementById('release-modal');
    var form = document.getElementById('release-form');
    if (!dialog || !form) {
      return;
    }

    var entryIdField = document.getElementById('release-entry-id');
    var reasonField = document.getElementById('release-reason');
    var summaryEl = document.getElementById('release-modal-summary');
    var statusEl = document.getElementById('release-form-status');
    var cancelBtn = document.getElementById('release-modal-cancel');

    document.querySelectorAll('.js-open-release-modal').forEach(function (btn) {
      btn.addEventListener('click', function () {
        entryIdField.value = btn.getAttribute('data-entry-id') || '';
        reasonField.value = 'Nisaab tamam';
        statusEl.textContent = '';

        var day = btn.getAttribute('data-day') || '';
        var period = btn.getAttribute('data-period') || '';
        var klass = btn.getAttribute('data-class') || '';
        var subject = btn.getAttribute('data-subject') || '';
        summaryEl.textContent = day + ' · ' + period + ' · ' + klass + (subject ? ' · ' + subject : '');

        if (typeof dialog.showModal === 'function') {
          dialog.showModal();
        } else {
          dialog.setAttribute('open', 'open');
        }
      });
    });

    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () {
        if (typeof dialog.close === 'function') {
          dialog.close();
        } else {
          dialog.removeAttribute('open');
        }
      });
    }

    form.addEventListener('submit', function (event) {
      // If the browser has no fetch(), fall back to a normal form submit
      // (the form's method/action attributes already point at the right
      // place, minus the CSRF hidden field which we add here).
      if (typeof window.fetch !== 'function') {
        return;
      }

      event.preventDefault();
      statusEl.textContent = 'Sending…';

      fetch('/api/release_submit.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-Token': csrfToken()
        },
        body: JSON.stringify({
          csrf_token: csrfToken(),
          entry_id: entryIdField.value,
          reason: reasonField.value
        })
      })
        .then(function (res) {
          return res.json().then(function (data) {
            return { status: res.status, data: data };
          });
        })
        .then(function (result) {
          if (result.data && result.data.ok) {
            statusEl.textContent = 'Sent for approval.';
            window.setTimeout(function () {
              window.location.reload();
            }, 500);
          } else {
            statusEl.textContent = (result.data && result.data.error) || 'Could not send request.';
          }
        })
        .catch(function () {
          statusEl.textContent = 'Network error. Please try again.';
        });
    });
  }

  // ---------------------------------------------------------------------
  // Take Period: auto-submit the class <select> so picking a class
  // immediately reloads the open-periods list, no extra tap needed.
  // ---------------------------------------------------------------------
  function initClassAutoSubmit() {
    var select = document.getElementById('class_id');
    var form = document.getElementById('class-filter-form');
    if (!select || !form) {
      return;
    }

    select.addEventListener('change', function () {
      form.submit();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initDayChips();
    initReleaseModal();
    initClassAutoSubmit();
  });
})();
