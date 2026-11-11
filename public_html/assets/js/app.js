/*
 * Agency OS — scripts de l'application (lots 1 et 2).
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

function confirmAction(title, text, okLabel, tone = 'danger') {
  const dialog = document.querySelector('[data-confirm-dialog]');
  if (!dialog || typeof dialog.showModal !== 'function') return Promise.resolve(false);
  dialog.querySelector('[data-confirm-title]').textContent = title;
  dialog.querySelector('[data-confirm-text]').textContent = text;
  const ok = dialog.querySelector('[data-confirm-ok]');
  ok.textContent = okLabel;
  ok.className = `btn ${tone === 'primary' ? 'btn--primary' : 'btn--danger'}`;
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

/* ---------- Diagnostic : démarrage, abandon du brouillon ---------- */
document.querySelectorAll('[data-diagnostic-start]').forEach((button) => {
  button.addEventListener('click', async () => {
    button.setAttribute('aria-busy', 'true');
    const body = button.dataset.from ? { from: button.dataset.from } : {};
    const result = await api('POST', '/api/v1/diagnostics', body);
    if (result.ok) {
      window.location.assign('/app/diagnostic/questionnaire?etape=1');
    } else {
      button.removeAttribute('aria-busy');
      toast(result.error?.message ?? 'Erreur.');
    }
  });
});

document.querySelector('[data-diagnostic-discard]')?.addEventListener('click', async (event) => {
  const id = event.currentTarget.dataset.diagnosticDiscard;
  if (!(await confirmAction('Abandonner le brouillon ?', 'Les réponses de ce diagnostic en cours seront supprimées. Les diagnostics terminés sont conservés.', 'Abandonner le brouillon'))) return;
  const result = await api('DELETE', `/api/v1/diagnostics/${encodeURIComponent(id)}`);
  if (result.ok) window.location.reload(); else toast(result.error?.message ?? 'Erreur.');
});

/* ---------- Diagnostic : sauvegarde automatique de chaque réponse ---------- */
const diagForm = document.querySelector('[data-diagnostic-form]');
if (diagForm) {
  const id = diagForm.dataset.id;
  const status = document.querySelector('[data-save-status]');
  const setStatus = (text, ok) => {
    if (!status) return;
    status.textContent = text;
    status.classList.toggle('inline-status--ok', ok === true);
  };
  const time = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' });
  let pending = Promise.resolve();
  let sequence = 0;

  const applyProgress = (progress) => {
    const applicable = new Set(progress.applicable_questions);
    // Questions conditionnelles : affichées ou masquées selon les réponses (règles calculées par le serveur).
    diagForm.querySelectorAll('[data-question]').forEach((fieldset) => {
      fieldset.hidden = !applicable.has(fieldset.dataset.question);
    });
    const bar = document.querySelector('[data-diag-progress]');
    if (bar) {
      bar.setAttribute('aria-valuenow', String(progress.answered));
      bar.setAttribute('aria-valuemax', String(progress.applicable));
      const pct = progress.applicable === 0 ? 0 : Math.floor((100 * progress.answered) / progress.applicable);
      bar.querySelector('.progress__bar')?.style.setProperty('--value', String(pct));
    }
    for (const [code, d] of Object.entries(progress.by_dimension)) {
      const step = document.querySelector(`[data-step-code="${CSS.escape(code)}"]`);
      if (step && !step.hasAttribute('aria-current')) {
        const done = d.applicable > 0 && d.answered === d.applicable;
        if (done) step.dataset.state = 'done'; else delete step.dataset.state;
      }
    }
    const remaining = document.querySelector('[data-diag-remaining]');
    if (remaining) {
      remaining.textContent = progress.ready
        ? 'Toutes les questions ont une réponse : vous pouvez terminer le diagnostic.'
        : `${progress.answered} réponse(s) sur ${progress.applicable}. Vous pouvez terminer avec des questions sans réponse : les dimensions trop incomplètes seront marquées « Données insuffisantes ».`;
    }
  };

  diagForm.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'radio') return;
    const fieldset = input.closest('[data-question]');
    fieldset?.querySelector('.question__hint')?.remove(); // la suggestion du profil devient une réponse de l'utilisateur
    const mine = ++sequence;
    setStatus('Enregistrement…');
    // Envoi séquentiel : l'ordre des réponses est conservé, seule la dernière réponse met à jour l'affichage.
    pending = pending.then(async () => {
      const result = await api('PUT', `/api/v1/diagnostics/${encodeURIComponent(id)}/answers`, { question: input.name, option: input.value });
      if (!result.ok) {
        setStatus('Non enregistré');
        toast(result.error?.message ?? 'La réponse n\'a pas pu être enregistrée.');
        return;
      }
      if (mine === sequence) {
        applyProgress(result.data.progress);
        setStatus(`Enregistré à ${time.format(new Date())}`, true);
      }
    });
  });

  diagForm.addEventListener('submit', (event) => event.preventDefault());

  document.querySelector('[data-diagnostic-complete]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const summary = diagForm.querySelector('[data-form-summary]');
    const fail = (error) => {
      button.removeAttribute('aria-busy');
      if (summary) {
        summary.hidden = false;
        summary.textContent = error?.fields?.answers ?? error?.message ?? 'Erreur.';
      }
    };
    button.setAttribute('aria-busy', 'true');
    await pending;
    const url = `/api/v1/diagnostics/${encodeURIComponent(id)}/complete`;
    let result = await api('POST', url, {});
    if (!result.ok && result.status === 422 && /sans réponse/.test(result.error?.fields?.answers ?? '')) {
      // Questions sans réponse : clôture « partielle » seulement après confirmation explicite.
      const ok = await confirmAction(
        'Terminer avec des réponses manquantes ?',
        `${result.error.fields.answers} Elles seront exclues du calcul : les dimensions trop incomplètes apparaîtront « Données insuffisantes » et le score global ne sera affiché que si les 6 dimensions sont calculables. Vous pourrez compléter plus tard.`,
        'Terminer quand même',
        'primary',
      );
      if (!ok) {
        button.removeAttribute('aria-busy');
        return;
      }
      result = await api('POST', url, { accept_missing: true });
    }
    if (result.ok) {
      window.location.assign(result.data.results_url);
      return;
    }
    fail(result.error);
  });
}

/* ---------- Radar du diagnostic (Chart.js, données fournies par le serveur) ---------- */
async function initRadar() {
  const canvas = document.getElementById('chart-radar');
  const source = document.getElementById('data-radar');
  if (!canvas || !source || !window.Chart) return;
  const data = JSON.parse(source.textContent ?? '{}');
  const css = getComputedStyle(document.documentElement);
  const token = (name) => css.getPropertyValue(name).trim();
  await document.fonts.ready;
  window.Chart.defaults.font.family = 'Poppins';
  window.Chart.defaults.color = token('--color-text-tertiary');
  const muted = token('--color-text-tertiary');
  new window.Chart(canvas, {
    type: 'radar',
    data: {
      labels: data.labels,
      datasets: [{
        data: data.values, spanGaps: false, borderColor: token('--color-accent'), backgroundColor: 'rgba(79, 70, 229, 0.12)',
        borderWidth: 2, pointRadius: 3, pointBackgroundColor: token('--color-accent'),
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => (ctx.raw === null ? 'Données insuffisantes' : `${Math.round(ctx.raw)} / 100`) } } },
      scales: { r: {
        min: 0, max: 100, ticks: { stepSize: 25, display: false },
        grid: { color: token('--color-border') }, angleLines: { color: token('--color-border') },
        pointLabels: { font: { size: 11 }, color: (ctx) => (data.values[ctx.index] === null ? muted : token('--color-text-secondary')) },
      } },
    },
  });
}
window.addEventListener('load', initRadar);

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
