(() => {
  const input = document.querySelector('#members');
  const output = document.querySelector('#plan-result');
  const copy = document.querySelector('#calculator-copy');
  if (!input || !output || !copy) return;
  const plans = [...document.querySelectorAll('[data-plan]')];
  input.addEventListener('input', () => {
    if (!input.value || !input.validity.valid) { output.textContent = '—'; return; }
    const plan = plans.find(p => Number(p.dataset.limit) >= Number(input.value));
    output.textContent = plan ? `${plan.dataset.name} · CHF ${plan.dataset.price} ${copy.dataset.month}` : copy.dataset.individual;
  });
})();
