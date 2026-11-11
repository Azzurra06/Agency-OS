/*
 * Agency OS — scripts de l'application (lot 1).
 * Règles (docs/04-securite.md §5) : aucun innerHTML ni eval, aucune formule métier (le serveur calcule),
 * aucune donnée financière dans le stockage du navigateur. Compatible CSP stricte et Trusted Types.
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

/* ---------- Messages d'erreur (codes renvoyés par l'API) ---------- */
const MESSAGES = {
  required: 'Ce champ est obligatoire.',
  unknown_field: 'Champ non autorisé.',
  decimal_must_be_string: 'Format de nombre invalide.',
  invalid_decimal: 'Nombre invalide (exemple : 1250,50).',
  invalid_integer: 'Nombre entier attendu.',
  invalid_date: 'Date invalide.',
  invalid_choice: 'Choix invalide.',
  invalid_string: 'Texte invalide.',
  invalid_characters: 'Caractères non autorisés.',
  too_small: 'Valeur trop petite.',
  too_large: 'Valeur trop grande.',
  too_short: 'Texte trop court.',
  too_long: 'Texte trop long.',
  invalid_period: 'Période invalide : la fin doit suivre le début, et la durée être comprise entre 1 et 24 mois.',
  exceeds_owner_total: 'Ne peut pas dépasser le coût complet de la rémunération.',
  exceeds_declared_costs: 'Ne peut pas dépasser les charges fixes et salariales déclarées.',
  quota_reached: 'Nombre maximal de prestations atteint.',
};

/* ---------- Client API : CSRF, JSON, erreurs normalisées ---------- */
async function api(method, url, body) {
  const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() } };
  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }
  let response;
  try {
    response = await fetch(url, options);
  } catch {
    return { ok: false, status: 0, error: { message: 'Connexion impossible. Vérifiez votre réseau et réessayez.' } };
  }
  if (response.status === 401) {
    window.location.assign('/connexion');
    return { ok: false, status: 401, error: { message: 'Session expirée.' } };
  }
  if (response.status === 204) {
    return { ok: true, status: 204, data: null };
  }
  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }
  if (!response.ok) {
    return { ok: false, status: response.status, error: payload?.error ?? { message: 'Une erreur est survenue.' } };
  }
  return { ok: true, status: response.status, data: payload?.data ?? null };
}

/* ---------- Toasts et confirmation ---------- */
function toast(message) {
  const region = document.querySelector('[data-toasts]');
  if (!region) return;
  const item = document.createElement('div');
  item.className = 'toast';
  item.setAttribute('role', 'status');
  item.textContent = message;
  region.append(item);
  setTimeout(() => item.remove(), 5000);
}

function confirmAction(title, text, okLabel) {
  const dialog = document.querySelector('[data-confirm-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return Promise.resolve(false);
  dialog.querySelector('[data-confirm-title]').textContent = title;
  dialog.querySelector('[data-confirm-text]').textContent = text;
  const ok = dialog.querySelector('[data-confirm-ok]');
  ok.textContent = okLabel;
  return new Promise((resolve) => {
    const close = (value) => {
      dialog.close();
      ok.removeEventListener('click', onOk);
      cancel.removeEventListener('click', onCancel);
      resolve(value);
    };
    const cancel = dialog.querySelector('[data-confirm-cancel]');
    const onOk = () => close(true);
    const onCancel = () => close(false);
    ok.addEventListener('click', onOk);
    cancel.addEventListener('click', onCancel);
    dialog.addEventListener('cancel', () => resolve(false), { once: true });
    dialog.showModal();
  });
}

/* ---------- Sérialisation : décimaux en chaînes, jamais de nombres JSON ---------- */
function normaliseDecimal(raw) {
  // Saisie fr-FR (« 1 250,50 ») → « 1250.50 ». Simple normalisation de format, aucun calcul.
  const cleaned = raw.replace(/[\s  ]/g, '').replace(',', '.');
  return cleaned === '' ? null : cleaned;
}

function serialise(container) {
  const data = {};
  container.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
    if (field.name === '_csrf' || field.disabled) return;
    const type = field.dataset.type;
    if (type === 'bool') {
      data[field.name] = field.checked;
    } else if (type === 'decimal') {
      data[field.name] = normaliseDecimal(field.value);
    } else if (type === 'int') {
      const value = field.value.trim();
      data[field.name] = value === '' ? null : value;
    } else {
      const value = field.value.trim();
      data[field.name] = value === '' ? null : value;
    }
  });
  return data;
}

function showErrors(container, error) {
  container.querySelectorAll('[data-error-for]').forEach((el) => { el.textContent = ''; });
  container.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
  const summary = container.querySelector('[data-form-summary]') ?? container.closest('form')?.querySelector('[data-form-summary]');
  if (summary) {
    summary.hidden = !error;
    summary.textContent = error ? (error.message ?? 'Certaines valeurs sont invalides.') : '';
  }
  if (!error?.fields) return;
  for (const [name, code] of Object.entries(error.fields)) {
    const field = container.querySelector(`[name="${CSS.escape(name)}"]`);
    if (field) field.setAttribute('aria-invalid', 'true');
    const slot = container.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
    if (slot) slot.textContent = MESSAGES[code] ?? 'Valeur invalide.';
  }
}

/* ---------- Formulaires branchés sur l'API ---------- */
document.querySelectorAll('form[data-api-form]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    submit?.setAttribute('aria-busy', 'true');
    const result = await api(form.dataset.method ?? 'POST', form.dataset.action, serialise(form));
    submit?.removeAttribute('aria-busy');
    if (!result.ok) {
      showErrors(form, result.error);
      form.querySelector('[aria-invalid="true"]')?.focus();
      return;
    }
    showErrors(form, null);
    if (form.dataset.redirect) {
      window.location.assign(form.dataset.redirect);
    } else if (form.hasAttribute('data-reload')) {
      window.location.reload();
    } else {
      toast('Modifications enregistrées.');
      setTimeout(() => window.location.reload(), 600);
    }
  });
});

/* ---------- Prestations : enregistrement et suppression par ligne ---------- */
document.querySelectorAll('[data-service-row]').forEach((row) => {
  const id = row.dataset.id;
  row.querySelector('[data-service-save]')?.addEventListener('click', async () => {
    const result = await api('PATCH', `/api/v1/services/${encodeURIComponent(id)}`, serialise(row));
    if (result.ok) {
      toast('Prestation enregistrée.');
    } else {
      toast(result.error?.fields ? 'Certaines valeurs sont invalides.' : (result.error?.message ?? 'Erreur.'));
      showErrors(row, result.error);
    }
  });
  row.querySelector('[data-service-delete]')?.addEventListener('click', async (event) => {
    const name = event.currentTarget.dataset.name ?? '';
    if (!(await confirmAction('Supprimer la prestation ?', `« ${name} » sera supprimée définitivement.`, 'Supprimer la prestation'))) return;
    const result = await api('DELETE', `/api/v1/services/${encodeURIComponent(id)}`);
    if (result.ok) {
      row.remove();
      toast('Prestation supprimée.');
    } else {
      toast(result.error?.message ?? 'Erreur.');
    }
  });
});

/* ---------- Fin de l'onboarding ---------- */
document.querySelector('[data-finish-onboarding]')?.addEventListener('click', async (event) => {
  event.currentTarget.setAttribute('aria-busy', 'true');
  const result = await api('PATCH', '/api/v1/organization/onboarding', { step: 3, completed: true });
  if (result.ok) {
    window.location.assign('/app');
  } else {
    event.currentTarget.removeAttribute('aria-busy');
    toast(result.error?.message ?? 'Erreur.');
  }
});

/* ---------- Jauges : valeur portée par data-value, appliquée via CSSOM ---------- */
document.querySelectorAll('[data-value]').forEach((el) => el.style.setProperty('--value', el.dataset.value));

/* ---------- Bulles d'information cliquables ---------- */
const tipButtons = [...document.querySelectorAll('.tip .info-btn[aria-controls]')];
const closeTip = (button, restoreFocus = false) => {
  button.setAttribute('aria-expanded', 'false');
  document.getElementById(button.getAttribute('aria-controls')).hidden = true;
  if (restoreFocus) button.focus();
};
const openTip = (button) => {
  tipButtons.forEach((other) => other !== button && closeTip(other));
  const content = document.getElementById(button.getAttribute('aria-controls'));
  content.hidden = false;
  button.setAttribute('aria-expanded', 'true');
  content.style.setProperty('--tip-shift', '0px');
  const rect = content.getBoundingClientRect();
  let shift = 0;
  if (rect.right > window.innerWidth - 8) shift = window.innerWidth - 8 - rect.right;
  if (rect.left + shift < 8) shift = 8 - rect.left;
  content.style.setProperty('--tip-shift', `${Math.round(shift)}px`);
};
tipButtons.forEach((button) => {
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    if (button.getAttribute('aria-expanded') === 'true') closeTip(button); else openTip(button);
  });
});
document.addEventListener('click', (event) => {
  tipButtons.forEach((button) => {
    if (!document.getElementById(button.getAttribute('aria-controls')).contains(event.target)) closeTip(button);
  });
});
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  const open = tipButtons.find((button) => button.getAttribute('aria-expanded') === 'true');
  if (open) closeTip(open, true);
});

/* ---------- Compte à rebours de la démo (affichage uniquement) ---------- */
const countdown = document.querySelector('[data-expires]');
if (countdown) {
  const expires = Date.parse(countdown.dataset.expires);
  const render = () => {
    const seconds = Math.max(0, Math.floor((expires - Date.now()) / 1000));
    if (seconds === 0) {
      window.location.assign('/demo/expiree');
      return;
    }
    countdown.textContent = `${Math.floor(seconds / 3600)} h ${String(Math.floor((seconds % 3600) / 60)).padStart(2, '0')}`;
  };
  render();
  setInterval(render, 30000);
}
