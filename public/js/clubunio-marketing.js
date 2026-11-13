const inquiryForm = document.querySelector('.inquiry-form');
if (inquiryForm) {
    document.querySelectorAll('a[href="#inquiry"]').forEach(link => {
        link.addEventListener('click', () => {
            inquiryForm.elements.namedItem('kind').value = link.dataset.inquiryKind || 'demo';
            inquiryForm.elements.namedItem('plan').value = link.dataset.inquiryPlan || '';
        });
    });
}
