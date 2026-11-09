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

const inquiryForm = document.querySelector('.inquiry-form');

if (inquiryForm) {
    const kind = inquiryForm.elements.namedItem('kind');
    let plan = '';
    document.querySelectorAll('a[href="#inquiry"]').forEach(link => {
        link.addEventListener('click', () => {
            kind.value = link.dataset.inquiryKind || 'demo';
            plan = link.dataset.inquiryPlan || '';
        });
    });
    inquiryForm.querySelector('button[type=submit]').disabled = false;
    inquiryForm.addEventListener('submit', event => {
        event.preventDefault();
        const lines = [kind.dataset.label + ': ' + kind.selectedOptions[0].textContent.trim()];
        for (const field of inquiryForm.querySelectorAll('input[data-label], textarea[data-label]')) {
            if (field.value.trim()) lines.push(field.dataset.label + ': ' + field.value.trim());
        }
        const interests = [...inquiryForm.querySelectorAll('input[name="interests"]:checked')];
        if (interests.length) {
            lines.push(inquiryForm.querySelector('legend').textContent + ': ' + interests.map(field => field.value).join(', '));
        }
        const subject = plan && kind.value !== 'demo' ? inquiryForm.dataset.subject + ' — ' + plan : inquiryForm.dataset.subject;
        window.location.href = 'mailto:' + inquiryForm.dataset.recipient
            + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(lines.join('\r\n'));
    });
}
