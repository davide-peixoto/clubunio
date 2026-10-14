document.querySelectorAll('.expense-line-form').forEach((form) => {
    const type = form.querySelector('[name="expense_type_id"]');
    const amount = form.querySelector('[name="amount"]');
    type.addEventListener('change', () => {
        const proposed = type.selectedOptions[0]?.dataset.defaultAmount;
        if (proposed !== undefined && proposed !== '') amount.value = proposed;
    });
});
