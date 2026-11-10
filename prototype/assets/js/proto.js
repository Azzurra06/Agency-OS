/*
 * Agency OS — scripts du prototype statique.
 * Règles respectées (docs/04-securite.md §5) : aucun innerHTML, aucune formule métier
 * (les valeurs affichées sont statiques et fictives), données des graphiques lues dans
 * des blocs <script type="application/json">.
 */

const css = getComputedStyle(document.documentElement);
const token = (name) => css.getPropertyValue(name).trim();

/* Largeurs et jauges : valeur portée par data-value, appliquée via CSSOM (compatible CSP). */
document.querySelectorAll('[data-value]').forEach((el) => {
  el.style.setProperty('--value', el.dataset.value);
});

/* Pastilles de scénario et onglets : simple bascule d'état ARIA. */
document.querySelectorAll('[data-toggle-group]').forEach((group) => {
  group.addEventListener('click', (event) => {
    const item = event.target.closest('[aria-pressed], [role="tab"]');
    if (!item || !group.contains(item) || item.dataset.static === 'true') return;
    const attr = item.hasAttribute('aria-pressed') ? 'aria-pressed' : 'aria-selected';
    group.querySelectorAll(`[${attr}]`).forEach((other) => other.setAttribute(attr, 'false'));
    item.setAttribute(attr, 'true');
  });
});

/* Bulles d'information cliquables : clic ou toucher, Entrée/Espace, Échap, clic extérieur. */
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
  // Garder la bulle entièrement dans l'écran (marge de 8 px) : décalage horizontal via CSSOM.
  content.style.setProperty('--tip-shift', '0px');
  const rect = content.getBoundingClientRect();
  const margin = 8;
  let shift = 0;
  if (rect.right > window.innerWidth - margin) shift = window.innerWidth - margin - rect.right;
  if (rect.left + shift < margin) shift = margin - rect.left;
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
    const content = document.getElementById(button.getAttribute('aria-controls'));
    if (!content.contains(event.target)) closeTip(button);
  });
});
document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  const open = tipButtons.find((button) => button.getAttribute('aria-expanded') === 'true');
  if (open) closeTip(open, true);
});

/* Alternative accessible des graphiques : « Voir les données ». */
document.querySelectorAll('[data-show-table]').forEach((button) => {
  const target = document.getElementById(button.dataset.showTable);
  button.addEventListener('click', () => {
    const hidden = target.hasAttribute('hidden');
    target.toggleAttribute('hidden', !hidden);
    button.setAttribute('aria-expanded', String(hidden));
    button.textContent = hidden ? 'Masquer les données' : 'Voir les données';
  });
});

/* Graphiques (Chart.js, chargé localement). */
const readJson = (id) => JSON.parse(document.getElementById(id).textContent);
const euro = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });

function baseOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: token('--color-text'),
        titleFont: { family: 'Poppins', weight: '500' },
        bodyFont: { family: 'Montserrat', weight: '600' },
        padding: 10,
        cornerRadius: 8,
        displayColors: false,
      },
    },
    font: { family: 'Poppins' },
  };
}

async function initCharts() {
  if (!window.Chart) return;
  // Attendre les polices : Chart.js mesure les libellés des axes avec la police réelle.
  await document.fonts.ready;
  window.Chart.defaults.font.family = 'Poppins';
  window.Chart.defaults.color = token('--color-text-tertiary');

  const mrr = document.getElementById('chart-mrr');
  if (mrr) {
    const data = readJson('data-mrr');
    const options = baseOptions();
    options.scales = {
      x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } },
      y: { grid: { color: token('--chart-grid') }, border: { display: false },
           ticks: { callback: (v) => euro.format(v), maxTicksLimit: 4 } },
    };
    options.plugins.tooltip.callbacks = {
      label: (ctx) => (ctx.raw === null ? 'Aucune donnée enregistrée ce jour' : euro.format(ctx.raw)),
    };
    new window.Chart(mrr, {
      type: 'line',
      data: { labels: data.labels, datasets: [{
        data: data.values, spanGaps: false, borderColor: token('--chart-1'), borderWidth: 2,
        stepped: 'before', pointRadius: 0, pointHoverRadius: 4, fill: false,
      }] },
      options,
    });
  }

  const style = document.getElementById('chart-style');
  if (style) {
    const data = readJson('data-style');
    const options = baseOptions();
    options.scales = {
      x: { grid: { display: false } },
      y: { grid: { color: token('--chart-grid') }, border: { display: false }, ticks: { callback: (v) => euro.format(v), maxTicksLimit: 4 } },
    };
    new window.Chart(style, {
      type: 'line',
      data: { labels: data.labels, datasets: [
        { label: 'Réel', data: data.real, spanGaps: false, borderColor: token('--chart-1'), borderWidth: 2, pointRadius: 3, pointBackgroundColor: token('--chart-1') },
        { label: 'Simulé', data: data.simulated, borderColor: token('--nature-simulated-fg'), borderDash: [6, 4], borderWidth: 2, pointRadius: 0 },
      ] },
      options,
    });
  }

  const radar = document.getElementById('chart-radar');
  if (radar) {
    const data = readJson('data-radar');
    const options = baseOptions();
    options.scales = { r: {
      min: 0, max: 100, ticks: { stepSize: 25, display: false },
      grid: { color: token('--chart-grid') }, angleLines: { color: token('--chart-grid') },
      pointLabels: { font: { family: 'Poppins', size: 11 }, color: (ctx) => (data.values[ctx.index] === null ? token('--color-text-tertiary') : token('--color-text-secondary')) },
    } };
    new window.Chart(radar, {
      type: 'radar',
      data: { labels: data.labels, datasets: [{
        data: data.values, spanGaps: false, borderColor: token('--chart-1'), backgroundColor: 'rgba(79, 70, 229, 0.12)',
        borderWidth: 2, pointRadius: 3, pointBackgroundColor: token('--chart-1'),
      }] },
      options,
    });
  }
}

window.addEventListener('load', initCharts);
