// Mobile nav toggle
const navToggle = document.getElementById('nav-toggle');
const mainNav = document.getElementById('main-nav');
if (navToggle && mainNav) {
  navToggle.addEventListener('click', () => {
    const isOpen = mainNav.classList.toggle('open');
    navToggle.setAttribute('aria-expanded', String(isOpen));
  });
  mainNav.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      mainNav.classList.remove('open');
      navToggle.setAttribute('aria-expanded', 'false');
    });
  });
}

// "Mehr über mich" toggle
const moreToggle = document.getElementById('more-toggle');
const moreContent = document.getElementById('more-content');
if (moreToggle && moreContent) {
  moreToggle.addEventListener('click', () => {
    const isOpen = moreToggle.getAttribute('aria-expanded') === 'true';
    moreToggle.setAttribute('aria-expanded', String(!isOpen));
    moreContent.hidden = isOpen;
  });
}

// FAQ accordion
document.querySelectorAll('.accordion-trigger').forEach(trigger => {
  trigger.addEventListener('click', () => {
    const panel = trigger.nextElementSibling;
    const isOpen = trigger.getAttribute('aria-expanded') === 'true';
    trigger.setAttribute('aria-expanded', String(!isOpen));
    panel.hidden = isOpen;
  });
});
