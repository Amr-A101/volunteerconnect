// /volcon/assets/js/alerts.js
document.addEventListener('DOMContentLoaded', function () {
  // Look for any alert element(s)
  var alerts = document.querySelectorAll('.vc-alert');
  if (!alerts || alerts.length === 0) return;

  alerts.forEach(function (alertEl) {
    // show with animation
    requestAnimationFrame(function () {
      alertEl.classList.add('vc-alert--visible');
    });

    var closeBtn = alertEl.querySelector('.vc-alert__close');
    var timeoutSec = parseInt(alertEl.getAttribute('data-timeout') || 0, 10);
    var timerId = null;
    var remaining = timeoutSec * 1000;
    var startTime = null;

    function startTimer() {
      if (!timeoutSec || timeoutSec <= 0) return;
      startTime = Date.now();
      timerId = setTimeout(dismiss, remaining);
    }

    function pauseTimer() {
      if (!timerId) return;
      clearTimeout(timerId);
      timerId = null;
      var elapsed = Date.now() - startTime;
      remaining = Math.max(0, remaining - elapsed);
    }

    function dismiss() {
      alertEl.classList.remove('vc-alert--visible');
      // remove from DOM after animation
      setTimeout(function () {
        if (alertEl && alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
      }, 260);
    }

    // start timer (if any)
    if (timeoutSec && timeoutSec > 0) startTimer();

    // pause/resume on hover
    alertEl.addEventListener('mouseenter', pauseTimer);
    alertEl.addEventListener('mouseleave', function () {
      // do not restart if already removed
      if (remaining > 0) startTimer();
    });

    // close button handler
    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        // cancel any timer and dismiss immediately
        if (timerId) clearTimeout(timerId);
        dismiss();
      });
    }
  });
});
