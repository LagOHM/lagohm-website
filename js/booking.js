(function () {
  const widget = document.getElementById('booking-widget');
  if (!widget) return;

  const csrfUrl = widget.dataset.csrfUrl;
  const slotsUrl = widget.dataset.slotsUrl;
  const createUrl = widget.dataset.createUrl;

  const serviceCards = Array.from(widget.querySelectorAll('.booking-service-card'));
  const dateStep = document.getElementById('booking-step-date');
  const dateInput = document.getElementById('booking-date');
  const slotsContainer = document.getElementById('booking-slots');
  const form = document.getElementById('booking-form');
  const summaryEl = document.getElementById('booking-summary');
  const errorEl = document.getElementById('booking-error');
  const successEl = document.getElementById('booking-success');
  const backBtn = document.getElementById('booking-back');
  const servicesEl = document.getElementById('booking-services');

  const csrfTokenField = document.getElementById('booking-csrf-token');
  const renderedAtField = document.getElementById('booking-rendered-at');
  const inputService = document.getElementById('booking-input-service');
  const inputDate = document.getElementById('booking-input-date');
  const inputTime = document.getElementById('booking-input-time');

  const lang = document.documentElement.lang === 'en' ? 'en' : 'de';
  const T = {
    de: {
      loading: 'Lade freie Termine …',
      noSlots: 'An diesem Tag sind leider keine freien Termine mehr verfügbar. Bitte ein anderes Datum wählen.',
      slotsFailed: 'Freie Termine konnten nicht geladen werden. Bitte versuch es gleich nochmal.',
      minutes: 'Minuten',
      at: (date, time) => `${date} um ${time} Uhr`,
      locale: 'de-DE',
      dateFormat: { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' },
      sending: 'Wird gebucht …',
      thanks: (name) => (name ? `Danke, ${name} – dein Termin ist gebucht!` : 'Dein Termin ist gebucht!'),
      mailNote: (email) => `Eine Bestätigung mit allen Details ist unterwegs an <strong>${email}</strong>. Falls sie nicht ankommt, schau bitte auch im Spam-Ordner nach.`,
      again: 'Weiteren Termin buchen',
      taken: 'Dieser Termin ist leider gerade nicht mehr frei. Bitte eine andere Zeit wählen.',
      failed: 'Die Buchung konnte nicht abgeschickt werden. Bitte versuch es nochmal.',
    },
    en: {
      loading: 'Loading available times …',
      noSlots: 'Sorry, there are no free times left on this day. Please choose another date.',
      slotsFailed: 'Available times could not be loaded. Please try again in a moment.',
      minutes: 'minutes',
      at: (date, time) => `${date} at ${time}`,
      locale: 'en-GB',
      dateFormat: { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' },
      sending: 'Booking …',
      thanks: (name) => (name ? `Thank you, ${name} – your appointment is booked!` : 'Your appointment is booked!'),
      mailNote: (email) => `A confirmation with all the details is on its way to <strong>${email}</strong>. If it doesn't arrive, please check your spam folder too.`,
      again: 'Book another appointment',
      taken: 'Sorry, this time has just been taken. Please choose another one.',
      failed: 'The booking could not be sent. Please try again.',
    },
  }[lang];

  let selected = { service: null, serviceName: null, duration: null, price: null };

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // Clear, unmistakable confirmation: the whole widget turns into a success card.
  function showSuccess(data, firstName, email) {
    servicesEl.hidden = true;
    dateStep.hidden = true;
    form.hidden = true;
    successEl.innerHTML = '<div class="booking-success-icon" aria-hidden="true">'
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>'
      + `<h3>${escapeHtml(T.thanks(firstName))}</h3>`
      + `<div class="booking-success-details"><strong>${escapeHtml(data.service)}</strong><br>${escapeHtml(data.date_label)}</div>`
      + `<p>${T.mailNote(escapeHtml(email))}</p>`
      + `<button type="button" class="btn btn-ghost btn-small" id="booking-again">${T.again}</button>`;
    successEl.hidden = false;
    successEl.focus({ preventScroll: true });
    successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('booking-again').addEventListener('click', () => {
      successEl.hidden = true;
      servicesEl.hidden = false;
      serviceCards.forEach((c) => c.classList.remove('is-selected'));
      selected = { service: null, serviceName: null, duration: null, price: null };
      fetchCsrf();
      servicesEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  function toYmd(date) {
    return date.toISOString().slice(0, 10);
  }

  function initDateBounds() {
    const today = new Date();
    const max = new Date();
    max.setDate(max.getDate() + 60);
    dateInput.min = toYmd(today);
    dateInput.max = toYmd(max);
  }

  function fetchCsrf() {
    renderedAtField.value = (Date.now() / 1000).toString();
    return fetch(csrfUrl)
      .then((r) => r.json())
      .then((data) => { csrfTokenField.value = data.token; })
      .catch(() => {});
  }

  function selectService(card) {
    serviceCards.forEach((c) => c.classList.remove('is-selected'));
    card.classList.add('is-selected');
    selected.service = card.dataset.service;
    selected.serviceName = card.dataset.serviceName;
    selected.duration = card.dataset.duration;
    selected.price = card.dataset.price;

    dateStep.hidden = false;
    form.hidden = true;
    successEl.hidden = true;
    slotsContainer.innerHTML = '';

    if (dateInput.value) {
      loadSlots();
    }
  }

  function loadSlots() {
    if (!selected.service || !dateInput.value) return;
    slotsContainer.innerHTML = `<p class="booking-loading">${T.loading}</p>`;

    const url = `${slotsUrl}?service=${encodeURIComponent(selected.service)}&date=${encodeURIComponent(dateInput.value)}`;
    fetch(url)
      .then((r) => r.json())
      .then((data) => {
        slotsContainer.innerHTML = '';
        if (!data.slots || data.slots.length === 0) {
          slotsContainer.innerHTML = `<p class="booking-no-slots">${T.noSlots}</p>`;
          return;
        }
        data.slots.forEach((time) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'booking-slot-btn';
          btn.textContent = time;
          btn.addEventListener('click', () => selectSlot(time));
          slotsContainer.appendChild(btn);
        });
      })
      .catch(() => {
        slotsContainer.innerHTML = `<p class="booking-no-slots">${T.slotsFailed}</p>`;
      });
  }

  function formatDateLabel(ymd) {
    const d = new Date(ymd + 'T00:00:00');
    return d.toLocaleDateString(T.locale, T.dateFormat);
  }

  function selectSlot(time) {
    inputService.value = selected.service;
    inputDate.value = dateInput.value;
    inputTime.value = time;

    summaryEl.innerHTML = `<strong>${selected.serviceName}</strong> (${selected.duration} ${T.minutes})<br>`
      + `${T.at(formatDateLabel(dateInput.value), time)}<br>`
      + `${selected.price}`;

    errorEl.hidden = true;
    dateStep.hidden = true;
    form.hidden = false;
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function showError(message) {
    errorEl.textContent = message;
    errorEl.hidden = false;
  }

  function submitForm(event) {
    event.preventDefault();
    errorEl.hidden = true;

    const submitBtn = form.querySelector('button[type="submit"]');
    const submitLabel = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.classList.add('is-loading');
    submitBtn.innerHTML = `<span class="btn-spinner" aria-hidden="true"></span>${T.sending}`;

    const payload = {
      csrf_token: csrfTokenField.value,
      form_rendered_at: parseFloat(renderedAtField.value),
      service: inputService.value,
      date: inputDate.value,
      time: inputTime.value,
      name: form.querySelector('[name="name"]').value,
      email: form.querySelector('[name="email"]').value,
      phone: form.querySelector('[name="phone"]').value,
      note: form.querySelector('[name="note"]').value,
      website: form.querySelector('[name="website"]').value,
      gdpr_consent: form.querySelector('[name="gdpr_consent"]').checked,
      lang,
    };

    fetch(createUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (!r.ok) {
          throw { status: r.status, data };
        }
        return data;
      })
      .then((data) => {
        const firstName = payload.name.trim().split(/\s+/)[0] || '';
        form.reset();
        showSuccess(data, firstName, payload.email.trim());
      })
      .catch((err) => {
        if (err && err.status === 409) {
          showError(T.taken);
          form.hidden = true;
          dateStep.hidden = false;
          loadSlots();
        } else {
          const msg = (err && err.data && err.data.error) || T.failed;
          showError(msg);
        }
      })
      .finally(() => {
        submitBtn.disabled = false;
        submitBtn.classList.remove('is-loading');
        submitBtn.innerHTML = submitLabel;
      });
  }

  serviceCards.forEach((card) => {
    card.addEventListener('click', () => selectService(card));
  });

  dateInput.addEventListener('change', loadSlots);
  form.addEventListener('submit', submitForm);

  backBtn.addEventListener('click', () => {
    form.hidden = true;
    dateStep.hidden = false;
  });

  // "Termin buchen" buttons on the Massage/Yoga panels pre-select that service.
  document.querySelectorAll('[data-booking-service]').forEach((link) => {
    link.addEventListener('click', () => {
      const slug = link.dataset.bookingService;
      const card = serviceCards.find((c) => c.dataset.service === slug);
      if (card) {
        window.setTimeout(() => selectService(card), 300);
      }
    });
  });

  initDateBounds();
  fetchCsrf();
})();
