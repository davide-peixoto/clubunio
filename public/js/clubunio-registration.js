const recipient = document.querySelector('[name="billing_recipient_id"]');
if (recipient) {
    const form = recipient.form;
    const birthDate = form.elements.namedItem('birth_date');
    const previousClub = form.elements.namedItem('has_previous_club');
    const emailFields = ['email', 'mother_email', 'father_email'];
    const parentFields = ['mother_name', 'mother_mobile', 'father_name', 'father_mobile'];
    function updateRequirements() {
        const today = new Date();
        const limit = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
        const [year, month, day] = birthDate.value.split('-').map(Number);
        const minor = Boolean(birthDate.value) && new Date(year, month - 1, day) > limit;
        const selectedEmail = recipient.selectedOptions[0]?.dataset.emailField;
        for (const name of [...emailFields, ...parentFields]) {
            const field = form.elements.namedItem(name);
            field.required = name === selectedEmail || (minor && name !== 'email');
        }
        for (const row of form.querySelectorAll('[data-previous-club]')) {
            const field = row.querySelector('input, select');
            row.hidden = previousClub.value !== 'J';
            field.required = !row.hidden;
            field.disabled = row.hidden;
        }
    }
    for (const field of [recipient, birthDate, previousClub]) {
        field.addEventListener('change', updateRequirements);
    }
    updateRequirements();
}
