'use strict';

document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});
document.getElementById('print-report')?.addEventListener('click', () => window.print());

const chartCanvas = document.getElementById('usage-chart');
if (chartCanvas && window.Chart) {
  const data = JSON.parse(document.getElementById('chart-data').textContent);
  new Chart(chartCanvas, {
    type: 'bar',
    data: { labels: data.labels, datasets: [{ label: 'Daily average (kWh/day)', data: data.values,
      backgroundColor: data.values.map((_, index) => index === data.values.length - 1 ? '#287655' : '#b6d4b5'),
      hoverBackgroundColor: '#287655', borderRadius: 5, maxBarThickness: 40 }] },
    options: { responsive: true, maintainAspectRatio: false, animation: false,
      plugins: { legend: { display: false }, tooltip: { backgroundColor: '#174d3c', padding: 12, displayColors: false,
        callbacks: { label: context => `${Number(context.raw).toFixed(2)} kWh / day` } } },
      scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: '#768275', font: { size: 10 }, maxRotation: 0, autoSkip: true } },
        y: { beginAtZero: true, border: { display: false }, grid: { color: '#edf1e9' }, ticks: { color: '#768275', font: { size: 10 }, maxTicksLimit: 5 } } }
    }
  });
}

const scenarioForm = document.getElementById('scenario-form');
if (scenarioForm) {
  const result = document.querySelector('.savings-result');
  const tariff = Number(result.dataset.tariff);
  const factor = Number(result.dataset.factor);
  const format = (value, decimals = 1) => value.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  const saveButton = document.getElementById('save-scenario');
  const updateScenario = () => {
    const valid = [...scenarioForm.querySelectorAll('input[type=number]')].every(input => input.validity.valid && input.value !== '');
    document.getElementById('scenario-error').textContent = valid ? '' : 'Enter valid numbers within the limits shown in each field.';
    saveButton.disabled = !valid;
    if (!valid) return;
    const fields = Object.fromEntries(new FormData(scenarioForm));
    const before = Number(fields.watts) * Number(fields.quantity) * Number(fields.hours) * Number(fields.days) / 1000;
    const after = Number(fields.new_watts) * Number(fields.quantity) * Number(fields.new_hours) * Number(fields.days) / 1000;
    const saved = before - after;
    document.getElementById('saved-kwh').textContent = format(saved, 2);
    document.getElementById('saved-cost').textContent = `₹${format(saved * tariff, 0)}`;
    document.getElementById('saved-co2').textContent = format(saved * factor);
    document.getElementById('before-kwh').textContent = `${format(before)} kWh`;
    document.getElementById('after-kwh').textContent = `${format(after)} kWh`;
    const maximum = Math.max(before, after, 1);
    document.getElementById('before-bar').style.width = `${before / maximum * 100}%`;
    document.getElementById('after-bar').style.width = `${after / maximum * 100}%`;
    document.getElementById('scenario-estimate').value = saved.toFixed(3);
    document.getElementById('scenario-notes').value = `Scenario estimate: ${fields.quantity} items, ${fields.watts} W at ${fields.hours} h/day → ${fields.new_watts} W at ${fields.new_hours} h/day, ${fields.days} days/month. Tariff ₹${tariff}/kWh; factor ${factor} kg CO₂/kWh. Not measured savings.`;
  };
  scenarioForm.addEventListener('input', updateScenario);
  scenarioForm.addEventListener('submit', event => { event.preventDefault(); updateScenario(); });
  document.getElementById('scenario-action-form').addEventListener('submit', event => {
    updateScenario();
    if (saveButton.disabled) event.preventDefault();
  });
  updateScenario();
}

const statusField = document.getElementById('action-status');
if (statusField) {
  const dateField = document.getElementById('completed-date');
  const updateStatus = () => { dateField.required = statusField.value === 'completed'; };
  statusField.addEventListener('change', updateStatus);
  updateStatus();
}
