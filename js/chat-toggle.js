(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiChat = {
    attach: function (context, settings) {
      once('ai-chat', '.ai-chat-container', context).forEach(function (container) {
        const header = container.querySelector('.ai-chat-header');
        const window = container.querySelector('.ai-chat-window');
        const icon = container.querySelector('.ai-chat-icon');

        const isVisible = localStorage.getItem('chatVisible') !== 'false';
        if (isVisible) {
          window.classList.add('is-open');
          icon.style.transform = 'rotate(180deg)';

        }
        else {
          window.classList.remove('is-open');
          icon.style.transform = 'rotate(0deg)';
        }

        header.addEventListener('click', function() {
          const isOpen = window.classList.toggle('is-open');
          icon.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
          localStorage.setItem('chatVisible', isOpen);
        });
      });
    }
  };
})(Drupal, once);
