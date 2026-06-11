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

  document.querySelectorAll('[data-mambers-profile-tabs]').forEach(function (nav) {
    var initial = nav.getAttribute('data-initial-tab');
    if (!initial && /(?:\?|&)tab=edit\b/.test(window.location.search)) {
      initial = 'edit';
    }
    if (initial) {
      var initialTab = nav.querySelector('[data-profile-tab="' + initial + '"]');
      if (initialTab) initialTab.click();
    }
    var tabs = nav.querySelectorAll('[data-profile-tab]');
    var root = nav.parentElement;
    if (!root) return;

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var id = tab.getAttribute('data-profile-tab');
        if (!id) return;
        tabs.forEach(function (t) {
          var active = t === tab;
          t.classList.toggle('is-active', active);
          t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('[data-profile-panel]').forEach(function (panel) {
          var show = panel.getAttribute('data-profile-panel') === id;
          panel.hidden = !show;
          panel.classList.toggle('is-active', show);
        });
      });
    });
  });

  function initBioEditors() {
    if (!window.MambersRichEditor) return;

    document.querySelectorAll('[data-mambers-bio-editor]').forEach(function (wrap) {
      if (wrap.getAttribute('data-rich-ready') === '1') return;
      var initialHtml = wrap.getAttribute('data-initial-html') || '';
      var initialText = wrap.getAttribute('data-initial-text') || '';
      var plainField = wrap.querySelector('[data-body-plain]');
      var htmlField = wrap.querySelector('[data-body-html]');

      wrap.insertAdjacentHTML(
        'beforeend',
        window.MambersRichEditor.toolbarHtml() +
          '<div class="mambers-rich-editor mambers-rich-editor--dark" contenteditable="true" data-rich-editor data-placeholder="Tell the village about you…"></div>' +
          window.MambersRichEditor.emojiPopHtml()
      );

      window.MambersRichEditor.init(wrap, {
        maxPlain: 500,
        initialHtml: initialHtml,
        initialText: initialHtml ? '' : initialText,
      });

      var form = wrap.closest('form');
      if (form) {
        form.addEventListener('submit', function () {
          if (plainField && htmlField) {
            var editor = wrap.querySelector('[data-rich-editor]');
            if (editor) {
              plainField.value = (editor.innerText || '').trim().slice(0, 500);
              htmlField.value = editor.innerHTML || '';
            }
          }
        });
      }

      wrap.setAttribute('data-rich-ready', '1');
    });
  }

  if (window.MambersRichEditor) {
    initBioEditors();
  } else {
    window.addEventListener('load', initBioEditors);
  }
})();
