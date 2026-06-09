(function () {
  'use strict';

  function openModal(id) {
    var modal = document.getElementById('mambers-modal-' + id);
    if (!modal) return;
    modal.hidden = false;
    document.body.classList.add('mambers-modal-open');
    var panel = modal.querySelector('.mambers-modal-panel');
    if (panel) panel.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    if (!document.querySelector('.mambers-modal:not([hidden])')) {
      document.body.classList.remove('mambers-modal-open');
    }
  }

  document.querySelectorAll('[data-mambers-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.getAttribute('data-mambers-open'));
    });
  });

  document.querySelectorAll('[data-mambers-close]').forEach(function (el) {
    el.addEventListener('click', function () {
      closeModal(el.closest('.mambers-modal'));
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.mambers-modal:not([hidden])').forEach(closeModal);
  });
})();
