/**
 * B442WP — App JavaScript
 * Vanilla JS, no dependencies.
 */
(function () {
  'use strict';

  // ── Mobile nav toggle ───────────────────────────────────────────────────────

  // Support both class naming conventions (nav__ BEM and legacy app-nav__)
  var navToggle = document.querySelector('.nav__mobile-toggle') ||
                  document.querySelector('.app-nav__toggle');
  var navMobile = document.querySelector('.nav__mobile-menu') ||
                  document.querySelector('.app-nav__mobile');

  if (navToggle && navMobile) {
    navToggle.addEventListener('click', function () {
      var open = navMobile.classList.toggle('is-open');
      navToggle.classList.toggle('is-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      navMobile.setAttribute('aria-hidden', open ? 'false' : 'true');
    });
    // Close on outside click
    document.addEventListener('click', function (e) {
      if (!navToggle.contains(e.target) && !navMobile.contains(e.target)) {
        navMobile.classList.remove('is-open');
        navToggle.classList.remove('is-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navMobile.setAttribute('aria-hidden', 'true');
      }
    });
    // Close on Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navMobile.classList.contains('is-open')) {
        navMobile.classList.remove('is-open');
        navToggle.classList.remove('is-open');
        navToggle.setAttribute('aria-expanded', 'false');
        navMobile.setAttribute('aria-hidden', 'true');
        navToggle.focus();
      }
    });
  }

  // ── Flash message auto-dismiss (5 seconds, then fade) ───────────────────────

  // Handle both .flash (new BEM) and .alert (legacy) elements
  function dismissFlash(el) {
    el.classList.add('is-hiding');
    setTimeout(function () {
      el.style.display = 'none';
    }, 250);
  }

  // Auto-dismiss all flash messages after 5 s
  document.querySelectorAll('.flash').forEach(function (el) {
    setTimeout(function () { dismissFlash(el); }, 5000);
  });

  // Legacy alert auto-dismiss via data attribute
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
    var delay = parseInt(el.getAttribute('data-auto-dismiss') || '5000', 10);
    setTimeout(function () {
      el.style.transition = 'opacity 0.4s ease';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    }, delay);
  });

  // Dismiss button — works for both .flash-dismiss (new) and .alert__close (legacy)
  document.querySelectorAll('.flash-dismiss, .alert__close').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.closest('.flash') || btn.closest('.alert');
      if (target) dismissFlash(target);
    });
  });

  // ── File upload area ─────────────────────────────────────────────────────────

  var uploadArea = document.querySelector('[data-upload-area]');
  var fileInput  = document.querySelector('input[type="file"][name="source_zip"]');
  var fileLabel  = document.querySelector('[data-file-label]');
  var uploadForm = document.querySelector('[data-upload-form]');
  var uploadBtn  = document.querySelector('[data-upload-submit]');
  var MAX_BYTES  = 50 * 1024 * 1024; // 50 MB

  if (uploadArea && fileInput) {
    // Drag-and-drop styling
    ['dragenter', 'dragover'].forEach(function (evt) {
      uploadArea.addEventListener(evt, function (e) {
        e.preventDefault();
        uploadArea.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      uploadArea.addEventListener(evt, function (e) {
        e.preventDefault();
        uploadArea.classList.remove('is-dragover');
      });
    });
    uploadArea.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length) {
        fileInput.files = files;
        handleFileSelected(files[0]);
      }
    });
    // Click on area → trigger file input
    uploadArea.addEventListener('click', function () { fileInput.click(); });

    fileInput.addEventListener('change', function () {
      if (fileInput.files.length) handleFileSelected(fileInput.files[0]);
    });

    function handleFileSelected(file) {
      var sizeWarning = document.querySelector('[data-size-warning]');
      if (fileLabel) {
        fileLabel.textContent = file.name + ' (' + formatBytes(file.size) + ')';
        fileLabel.style.display = 'block';
      }
      if (file.size > MAX_BYTES) {
        if (sizeWarning) {
          sizeWarning.textContent = 'File is too large (' + formatBytes(file.size) + '). Maximum is 50 MB.';
          sizeWarning.style.display = 'block';
        }
        if (uploadBtn) uploadBtn.disabled = true;
      } else {
        if (sizeWarning) sizeWarning.style.display = 'none';
        if (uploadBtn) uploadBtn.disabled = false;
      }
      // Highlight upload area as accepted
      uploadArea.classList.add('has-file');
    }
  }

  // Upload form submit feedback
  if (uploadForm && uploadBtn) {
    uploadForm.addEventListener('submit', function () {
      uploadBtn.disabled = true;
      // Support btn-label / btn-loading pattern used in templates
      var labelEl   = uploadBtn.querySelector('.btn-label');
      var loadingEl = uploadBtn.querySelector('.btn-loading');
      if (labelEl && loadingEl) {
        labelEl.hidden   = true;
        loadingEl.hidden = false;
      } else {
        uploadBtn.textContent = 'Analyzing…';
      }
      uploadBtn.classList.add('is-loading');
    });
  }

  // ── Generic form loading state (btn-label / btn-loading pattern) ─────────────

  // Any form with data-loading-form will swap btn-label/btn-loading on submit
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var submitBtn = form.querySelector('[type="submit"]');
      if (!submitBtn) return;
      var labelEl   = submitBtn.querySelector('.btn-label');
      var loadingEl = submitBtn.querySelector('.btn-loading');
      if (labelEl && loadingEl) {
        // Small delay so fast double-clicks are ignored before browser navigates
        setTimeout(function () {
          submitBtn.disabled = true;
          labelEl.hidden     = true;
          loadingEl.hidden   = false;
        }, 0);
      }
    });
  });

  // ── Password show/hide toggle ────────────────────────────────────────────────

  document.querySelectorAll('.form-input-toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var wrap  = btn.closest('.form-input-wrap');
      if (!wrap) return;
      var input = wrap.querySelector('input');
      if (!input) return;
      var isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      // Toggle eye icon class for CSS styling if needed
      btn.classList.toggle('is-showing', isPassword);
    });
  });

  // ── Conversion progress polling ─────────────────────────────────────────────

  var progressContainer = document.querySelector('[data-conversion-uuid]');

  if (progressContainer) {
    var convUuid   = progressContainer.getAttribute('data-conversion-uuid');
    var convStatus = progressContainer.getAttribute('data-conversion-status');
    var progressBar   = document.querySelector('[data-progress-bar]');
    var statusMessage = document.querySelector('[data-status-message]');

    if (convStatus === 'converting' || convStatus === 'paid') {
      var pollInterval = null;
      var fakeProgress = 5; // optimistic progress while real data loads

      // Fake progress animation while waiting for first real update
      if (progressBar) {
        progressBar.style.width = fakeProgress + '%';
      }

      var messages = [
        'Parsing your Base44 source files…',
        'Extracting design tokens…',
        'Generating WordPress theme structure…',
        'Building PHP templates…',
        'Converting Tailwind CSS to WordPress styles…',
        'Generating interactive JavaScript…',
        'Assembling WordPress theme zip…',
        'Almost done…',
      ];
      var msgIndex = 0;

      function updateFakeProgress() {
        if (fakeProgress < 85) {
          fakeProgress = Math.min(fakeProgress + Math.random() * 8, 85);
          if (progressBar) progressBar.style.width = Math.round(fakeProgress) + '%';
        }
        if (statusMessage && msgIndex < messages.length) {
          statusMessage.textContent = messages[msgIndex++];
        }
      }
      var fakeTimer = setInterval(updateFakeProgress, 4000);

      pollInterval = setInterval(function () {
        fetch('/api/convert-status/' + convUuid)
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status === 'complete') {
              clearInterval(pollInterval);
              clearInterval(fakeTimer);
              if (progressBar) progressBar.style.width = '100%';
              if (statusMessage) statusMessage.textContent = 'Conversion complete! Redirecting…';
              setTimeout(function () {
                window.location.href = '/download/' + convUuid;
              }, 1200);
            } else if (data.status === 'failed') {
              clearInterval(pollInterval);
              clearInterval(fakeTimer);
              if (statusMessage) {
                statusMessage.textContent = data.message || 'Conversion failed.';
                statusMessage.classList.add('text-danger');
              }
              var contactLink = document.querySelector('[data-error-contact]');
              if (contactLink) contactLink.style.display = 'block';
            } else if (data.ai_calls_used) {
              // Real progress based on AI calls (max ~10 calls per conversion)
              var realPct = Math.min(Math.round(data.ai_calls_used * 10), 90);
              if (realPct > fakeProgress) {
                fakeProgress = realPct;
                if (progressBar) progressBar.style.width = realPct + '%';
              }
            }
          })
          .catch(function () { /* network hiccup — keep polling */ });
      }, 3000);
    }

    if (convStatus === 'complete') {
      if (progressBar) progressBar.style.width = '100%';
      if (statusMessage) statusMessage.textContent = 'Conversion complete!';
      setTimeout(function () {
        window.location.href = '/download/' + convUuid;
      }, 800);
    }
  }

  // ── Pay page — prevent double-payment ──────────────────────────────────────

  var payButtons = document.querySelectorAll('[data-pay-button]');
  payButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      payButtons.forEach(function (b) {
        b.disabled = true;
        b.classList.add('is-loading');
      });
      btn.textContent = 'Redirecting to payment…';
    });
  });

  // ── Expiry countdown ────────────────────────────────────────────────────────

  var countdowns = document.querySelectorAll('[data-expires-at]');
  countdowns.forEach(function (el) {
    var expiresAt = new Date(el.getAttribute('data-expires-at') + ' UTC');

    function updateCountdown() {
      var now  = new Date();
      var diff = expiresAt - now;
      if (diff <= 0) {
        el.textContent = 'Expired';
        el.classList.add('text-danger');
        return;
      }
      var days  = Math.floor(diff / 86400000);
      var hours = Math.floor((diff % 86400000) / 3600000);
      if (days > 1) {
        el.textContent = days + ' days remaining';
      } else if (days === 1) {
        el.textContent = '1 day ' + hours + ' hrs remaining';
        el.classList.add('expiry-warning');
      } else if (hours > 0) {
        el.textContent = hours + ' hours remaining';
        el.classList.add('expiry-danger');
      } else {
        var mins = Math.floor((diff % 3600000) / 60000);
        el.textContent = mins + ' minutes remaining';
        el.classList.add('expiry-danger');
      }
    }
    updateCountdown();
    setInterval(updateCountdown, 60000);
  });

  // ── Copy-to-clipboard ────────────────────────────────────────────────────────

  document.querySelectorAll('[data-copy]').forEach(function (el) {
    el.addEventListener('click', function () {
      var text = el.getAttribute('data-copy');
      if (!text) return;
      navigator.clipboard.writeText(text).then(function () {
        var orig = el.textContent;
        el.textContent = 'Copied!';
        setTimeout(function () { el.textContent = orig; }, 1500);
      });
    });
  });

  // ── Status polling via data-poll-uuid on <body> ────────────────────────────

  (function initStatusPolling() {
    var uuid = document.body.dataset.pollUuid;
    if (!uuid) return;

    var INTERVAL    = 3000;
    var statusBadge = document.querySelector('.status-badge');
    var logEl       = document.querySelector('.conversion-log');
    var fillEl      = document.querySelector('.progress-bar__fill');
    var timer       = null;

    function poll() {
      fetch('/api/convert-status/' + encodeURIComponent(uuid), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (data) {
          var status = data.status || '';

          if (statusBadge && data.label) {
            statusBadge.textContent = data.label;
            statusBadge.className   = 'status-badge badge ' + (data.badge_class || 'badge--info');
          }
          if (logEl && data.log) { logEl.textContent = data.log; logEl.scrollTop = logEl.scrollHeight; }

          var pctMap = { pending: 5, parsed: 20, paid: 40, converting: 65, complete: 100, failed: 100 };
          if (fillEl) { fillEl.style.width = (pctMap[status] || 0) + '%'; }

          if (status === 'complete') {
            clearInterval(timer);
            setTimeout(function () { window.location.reload(); }, 1200);
          } else if (status === 'failed') {
            clearInterval(timer);
            var errEl = document.querySelector('.polling-error');
            if (!errEl) {
              errEl = document.createElement('div');
              errEl.className = 'form-alert form-alert--error polling-error';
              errEl.setAttribute('role', 'alert');
              var main = document.querySelector('main');
              if (main) main.prepend(errEl);
            }
            errEl.textContent = data.error_message || 'Conversion failed. Please contact support.';
          }
        })
        .catch(function (err) { console.warn('Status poll error:', err); });
    }

    poll();
    timer = setInterval(poll, INTERVAL);
  }());

  // ── Progress bars with data-progress attribute (animate on load) ──────────

  document.querySelectorAll('.progress-bar__fill[data-progress]').forEach(function (fill) {
    var target = parseInt(fill.getAttribute('data-progress'), 10) || 0;
    requestAnimationFrame(function () {
      setTimeout(function () { fill.style.width = target + '%'; }, 60);
    });
  });

  // ── .copy-btn class support (used in preview / download pages) ───────────

  document.querySelectorAll('.copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.dataset.copyText || '';
      if (!text) {
        var tgt = btn.closest('[data-copy-target]') ||
                  btn.nextElementSibling           ||
                  btn.previousElementSibling;
        text = (tgt && (tgt.href || tgt.textContent || '').trim()) || '';
      }
      if (!text) return;
      var orig = btn.dataset.originalText || btn.textContent;
      btn.dataset.originalText = orig;

      function done() {
        btn.textContent = 'Copied!';
        btn.classList.add('is-copied');
        setTimeout(function () {
          btn.textContent = orig;
          btn.classList.remove('is-copied');
          delete btn.dataset.originalText;
        }, 2000);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
      }
    });
  });

  // ── [data-expires] countdown support (supplements [data-expires-at]) ──────

  document.querySelectorAll('[data-expires]').forEach(function (el) {
    var raw = el.getAttribute('data-expires') || '';
    if (!/Z|[+-]\d{2}:\d{2}/.test(raw)) raw += ' UTC';
    var expiresAt = new Date(raw);
    if (isNaN(expiresAt.getTime())) return;

    function update() {
      var diff = Math.floor((expiresAt.getTime() - Date.now()) / 1000);
      if (diff <= 0) { el.textContent = 'Expired'; return; }
      var days  = Math.floor(diff / 86400);
      var hours = Math.floor((diff % 86400) / 3600);
      var mins  = Math.floor((diff % 3600) / 60);
      var label;
      if (days > 1)       label = days + ' days';
      else if (days === 1)label = '1 day ' + hours + 'h';
      else if (hours > 0) label = hours + 'h ' + mins + 'm';
      else                label = mins + 'm';
      el.textContent = 'Expires in ' + label;

      el.classList.remove('expiry-badge--ok', 'expiry-badge--warning', 'expiry-badge--danger');
      if      (diff < 3 * 86400) el.classList.add('expiry-badge--danger');
      else if (diff < 7 * 86400) el.classList.add('expiry-badge--warning');
      else                       el.classList.add('expiry-badge--ok');
    }
    update();
    setInterval(update, 60000);
  });

  // ── Clickable table rows (navigate on row click if action link present) ───

  document.querySelectorAll('.table__row').forEach(function (row) {
    var actionLink = row.querySelector('.table__cell--action a');
    if (!actionLink) return;
    row.style.cursor = 'pointer';
    row.addEventListener('click', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'a' || tag === 'button') return;
      actionLink.click();
    });
  });

  // ── Smooth anchor scroll ───────────────────────────────────────────────────

  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var id     = link.getAttribute('href').slice(1);
      var target = document.getElementById(id);
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      target.focus({ preventScroll: true });
    });
  });

  // ── Helpers ─────────────────────────────────────────────────────────────────

  function formatBytes(bytes) {
    if (bytes < 1024)     return bytes + ' B';
    if (bytes < 1048576)  return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

})();
