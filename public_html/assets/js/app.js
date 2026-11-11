/*
 * Agency OS — scripts de l'application (lots 1 à 3).
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

/* ---------- Pricing : création d'une simulation ---------- */
document.querySelector('[data-pricing-create]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const service = form.querySelector('[name="service"]').value;
  const result = await api('POST', '/api/v1/pricing/simulations', service ? { service } : {});
  if (result.ok) window.location.assign(`/app/pricing/${encodeURIComponent(result.data.public_id)}`);
  else toast(result.error?.message ?? 'Erreur.');
});

/* ---------- Pricing : simulateur ---------- */
const pricing = document.querySelector('[data-pricing]');
if (pricing) {
  const simId = pricing.dataset.simulation;
  const scenarioId = pricing.dataset.scenario;
  const editable = pricing.dataset.editable === '1';
  const form = pricing.querySelector('[data-pricing-form]');
  const page = (query = '') => `/app/pricing/${encodeURIComponent(simId)}${query}`;
  const euro = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  const euro0 = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
  const pct = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const pct2 = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const hours = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });
  // Affichage uniquement : les valeurs arrivent du serveur sous forme de chaînes déjà calculées.
  const money = (v) => (v === null || v === undefined ? '—' : euro.format(Number(v)));
  const percent = (v) => (v === null || v === undefined ? '—' : `${pct.format(Number(v))} %`);
  const ERRORS = {
    hourly_cost_unavailable: 'Coût horaire non calculable : complétez votre profil financier ou saisissez un coût horaire personnalisé.',
    margin_plus_variable_rate_too_high: 'La marge cible et le taux de charges variables dépassent 100 % du prix : réduisez la marge cible.',
    empty_scenario: 'Ajoutez au moins une ligne de temps ou de frais.',
  };
  const alertText = (code, gap) => ({
    price_below_floor: 'Le prix testé est sous le prix plancher : à ce prix, la prestation fait perdre de l’argent.',
    price_below_recommended: `Le prix testé est ${pct.format(-Number(gap))} % sous le prix recommandé : la marge cible n’est pas atteinte.`,
    variable_rate_zero: 'Aucune charge variable générale dans votre profil : le taux appliqué est de 0 %.',
    variable_rate_unavailable: 'Taux de charges variables générales non calculable (profil incomplet) : 0 % est utilisé.',
  }[code] ?? code);

  const setAll = (key, text) => pricing.querySelectorAll(`[data-r="${key}"]`).forEach((el) => { el.textContent = text; });

  const collect = () => {
    const data = serialise(form.querySelector('.form-section'));
    const body = {
      label: data.label ?? null,
      hourly_cost_mode: form.querySelector('[name="hourly_cost_mode"]:checked')?.value ?? 'profile',
      hourly_cost_custom: normaliseDecimal(form.querySelector('[name="hourly_cost_custom"]').value),
      contingency_pct: normaliseDecimal(form.querySelector('[name="contingency_pct"]').value),
      target_margin_pct: normaliseDecimal(form.querySelector('[name="target_margin_pct"]').value),
      proposed_price: normaliseDecimal(form.querySelector('[name="proposed_price"]').value),
      lines: [],
    };
    if (body.hourly_cost_mode !== 'custom') body.hourly_cost_custom = null;
    form.querySelectorAll('[data-line]').forEach((row) => {
      const kind = row.dataset.line;
      const label = row.querySelector('[data-field="label"]').value.trim();
      const value = normaliseDecimal(row.querySelector(kind === 'time' ? '[data-field="hours"]' : '[data-field="amount"]').value);
      if (label === '' && value === null) return; // ligne vide ignorée
      body.lines.push(kind === 'time' ? { kind, label: label || 'Phase', hours: value ?? '0' } : { kind, label: label || 'Frais', amount: value ?? '0' });
    });
    return body;
  };

  const render = (data) => {
    const error = data.error;
    const errorBox = pricing.querySelector('[data-r-error]');
    errorBox.hidden = !error;
    pricing.querySelector('[data-r-error-text]').textContent = error ? (ERRORS[error] ?? 'Calcul impossible avec ces paramètres.') : '';
    const r = data.results ?? {};
    const hc = data.inputs?.hourly_cost?.value;
    setAll('hourly_cost', hc ? `${euro.format(Number(hc))}/h` : 'non calculable');
    setAll('variable_rate', `${pct2.format(Number(data.inputs?.variable_rate_pct?.value ?? 0))} %`);
    setAll('total_hours', r.total_hours ? `${hours.format(Number(r.total_hours))} h` : '—');
    for (const key of ['direct_costs', 'base_cost', 'floor_price', 'recommended_price']) setAll(key, money(r[key]));
    pricing.querySelector('[data-r-rounded-line]').hidden = !data.rounded_price;
    setAll('rounded_price', data.rounded_price ? euro0.format(Number(data.rounded_price)) : '');
    const at = r.at_price;
    pricing.querySelector('[data-r-at-price]').hidden = !at;
    if (at) {
      for (const key of ['price', 'full_cost', 'result', 'annual_shortfall']) setAll(key, money(at[key]));
      for (const key of ['margin_on_price_pct', 'markup_on_cost_pct']) setAll(key, percent(at[key]));
      pricing.querySelector('[data-r-shortfall-line]').hidden = !at.annual_shortfall;
    } else {
      setAll('margin_on_price_pct', '—');
    }
    const alerts = pricing.querySelector('[data-r-alerts]');
    alerts.replaceChildren(...(data.alerts ?? []).map((a) => {
      const p = document.createElement('p');
      p.className = `alert alert--${a.level === 'danger' ? 'danger' : (a.level === 'warning' ? 'warning' : 'info')}`;
      p.textContent = alertText(a.code, at?.gap_to_recommended_pct);
      return p;
    }));
  };

  // Aperçu serveur : anti-rebond de 350 ms, requête précédente annulée, réponse périmée ignorée (X-Request-Seq).
  let timer = null;
  let controller = null;
  let seq = 0;
  const calcStatus = pricing.querySelector('[data-calc-status]');
  const saveStatus = pricing.querySelector('[data-save-status]');
  const preview = async () => {
    controller?.abort();
    controller = new AbortController();
    const mine = ++seq;
    if (calcStatus) calcStatus.textContent = 'Calcul…';
    let response;
    try {
      response = await fetch('/api/v1/pricing/calculate', {
        method: 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken(), 'X-Request-Seq': String(mine) },
        body: JSON.stringify({ ...collect(), simulation: simId }),
      });
    } catch {
      return; // annulée ou réseau : la prochaine saisie relancera le calcul
    }
    if (Number(response.headers.get('X-Request-Seq')) !== seq) return;
    const payload = await response.json().catch(() => null);
    if (!response.ok) {
      showErrors(form, payload?.error);
      if (calcStatus) calcStatus.textContent = 'Valeurs à corriger';
      return;
    }
    showErrors(form, null);
    render(payload.data);
    if (calcStatus) calcStatus.textContent = 'À jour';
  };
  const schedule = () => {
    if (!editable) return;
    if (saveStatus) saveStatus.textContent = 'Modifications non enregistrées : l’aperçu est calculé sans rien enregistrer.';
    clearTimeout(timer);
    timer = setTimeout(preview, 350);
  };

  if (editable) {
    form.addEventListener('input', schedule);
    form.addEventListener('change', (event) => {
      if (event.target.name === 'hourly_cost_mode') {
        form.querySelector('[data-custom-hc]').hidden = event.target.value !== 'custom';
      }
      schedule();
    });
    form.addEventListener('submit', (event) => event.preventDefault());
    form.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-line-remove]');
      if (remove) {
        remove.closest('tr').remove();
        schedule();
      }
      const add = event.target.closest('[data-line-add]');
      if (add) {
        const kind = add.dataset.lineAdd;
        const row = pricing.querySelector(`template[data-line-template="${kind}"]`).content.firstElementChild.cloneNode(true);
        form.querySelector(`[data-lines="${kind}"]`).append(row);
        row.querySelector('input').focus();
      }
    });
    pricing.querySelector('[data-pricing-template]')?.addEventListener('click', async (event) => {
      const result = await api('GET', `/api/v1/pricing/templates/${encodeURIComponent(event.currentTarget.dataset.pricingTemplate)}`);
      if (!result.ok) return;
      const body = form.querySelector('[data-lines="time"]');
      const template = pricing.querySelector('template[data-line-template="time"]');
      for (const line of result.data.lines) {
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelector('[data-field="label"]').value = line.label;
        row.querySelector('[data-field="hours"]').value = line.hours;
        body.append(row);
      }
      pricing.querySelector('[data-template-warning]').hidden = false;
      schedule();
    });
    pricing.querySelector('[data-pricing-save]')?.addEventListener('click', async (event) => {
      const button = event.currentTarget;
      button.setAttribute('aria-busy', 'true');
      const result = await api('PUT', `/api/v1/pricing/scenarios/${encodeURIComponent(scenarioId)}`, collect());
      button.removeAttribute('aria-busy');
      if (result.ok) {
        showErrors(form, null);
        if (saveStatus) saveStatus.textContent = `Scénario enregistré à ${new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date())}.`;
        toast('Scénario enregistré.');
      } else {
        showErrors(form, result.error);
      }
    });
  }

  const post = async (url, body, after) => {
    const result = await api('POST', url, body);
    if (result.ok) after(result.data); else toast(result.error?.fields ? Object.values(result.error.fields)[0] : (result.error?.message ?? 'Erreur.'));
    return result;
  };
  pricing.querySelector('[data-pricing-add-scenario]')?.addEventListener('click', (event) => post(
    `/api/v1/pricing/simulations/${encodeURIComponent(simId)}/scenarios`,
    { copy_of: event.currentTarget.dataset.copyOf },
    (data) => window.location.assign(page(`?scenario=${encodeURIComponent(data.public_id)}`)),
  ));
  pricing.querySelector('[data-pricing-reference]')?.addEventListener('click', async (event) => {
    const result = await api('PATCH', `/api/v1/pricing/simulations/${encodeURIComponent(simId)}`, { reference: event.currentTarget.dataset.pricingReference });
    if (result.ok) window.location.reload(); else toast(result.error?.message ?? 'Erreur.');
  });
  pricing.querySelector('[data-pricing-delete-scenario]')?.addEventListener('click', async (event) => {
    if (!(await confirmAction('Supprimer ce scénario ?', 'Ses paramètres et ses lignes seront supprimés.', 'Supprimer le scénario'))) return;
    const result = await api('DELETE', `/api/v1/pricing/scenarios/${encodeURIComponent(event.currentTarget.dataset.pricingDeleteScenario)}`);
    if (result.ok) window.location.assign(page()); else toast(result.error?.message ?? 'Erreur.');
  });
  pricing.querySelector('[data-pricing-delete]')?.addEventListener('click', async () => {
    if (!(await confirmAction('Supprimer ce brouillon ?', 'Ses scénarios seront supprimés. Les versions figées sont conservées.', 'Supprimer le brouillon'))) return;
    const result = await api('DELETE', `/api/v1/pricing/simulations/${encodeURIComponent(simId)}`);
    if (result.ok) window.location.assign('/app/pricing'); else toast(result.error?.message ?? 'Erreur.');
  });
  pricing.querySelector('[data-pricing-finalize]')?.addEventListener('click', async () => {
    if (!(await confirmAction(
      'Finaliser la simulation ?',
      'Les hypothèses (coût horaire, charges variables, prix actuel) et les résultats seront figés. Cette version deviendra la référence de la prestation pour la stratégie ; pour la modifier ensuite, vous créerez une nouvelle version. Pensez à enregistrer vos scénarios avant.',
      'Finaliser', 'primary',
    ))) return;
    await post(`/api/v1/pricing/simulations/${encodeURIComponent(simId)}/finalize`, {}, () => window.location.reload());
  });
  pricing.querySelector('[data-pricing-new-version]')?.addEventListener('click', () => post(
    `/api/v1/pricing/simulations/${encodeURIComponent(simId)}/new-version`, {},
    (data) => window.location.assign(`/app/pricing/${encodeURIComponent(data.public_id)}`),
  ));
  pricing.querySelector('[data-pricing-archive]')?.addEventListener('click', async () => {
    if (!(await confirmAction('Archiver cette version ?', 'Elle restera consultable, mais ne sera plus la référence de la prestation pour la stratégie.', 'Archiver', 'primary'))) return;
    await post(`/api/v1/pricing/simulations/${encodeURIComponent(simId)}/archive`, {}, () => window.location.reload());
  });
}

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
