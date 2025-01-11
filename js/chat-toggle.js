(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiChat = {
    attach: function (context, settings) {
      once('ai-chat', '.ai-chat-container', context).forEach(function (container) {
        const header = container.querySelector('.ai-chat-header');
        const window = container.querySelector('.ai-chat-window');
        const icon = container.querySelector('.ai-chat-icon');
        const is_visible = localStorage.getItem('aiChatVisible') !== 'false';
        if (is_visible) {
          window.classList.add('is-open');
          icon.style.transform = 'rotate(180deg)';
        }
        else {
          window.classList.remove('is-open');
          icon.style.transform = 'rotate(0deg)';
        }
        header.addEventListener('click', function() {
          const is_open = window.classList.toggle('is-open');
          icon.style.transform = is_open ? 'rotate(180deg)' : 'rotate(0deg)';
          localStorage.setItem('aiChatVisible', String(is_open));
        });
        document.addEventListener('keydown', function(event) {
          if (event.key === 'Escape' && window.classList.contains('is-open')) {
            window.classList.remove('is-open');
            icon.style.transform = 'rotate(0deg)';
            localStorage.setItem('aiChatVisible', 'false');
          }
          else if (event.ctrlKey && event.key === '/' && !window.classList.contains('is-open')) {
            window.classList.add('is-open');
            icon.style.transform = 'rotate(180deg)';
            localStorage.setItem('aiChatVisible', 'true');
          }
        });
      });
    }
  };

})(Drupal, once);
