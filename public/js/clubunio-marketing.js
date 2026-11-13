const inquiryForm = document.querySelector('.inquiry-form');
if (inquiryForm) {
    document.querySelectorAll('a[href="#inquiry"]').forEach(link => {
        link.addEventListener('click', () => {
            inquiryForm.elements.namedItem('kind').value = link.dataset.inquiryKind || 'demo';
            inquiryForm.elements.namedItem('plan').value = link.dataset.inquiryPlan || '';
        });
    });
}

const lightbox = document.querySelector('#marketing-lightbox');
if (lightbox && typeof lightbox.showModal === 'function') {
    const preview = lightbox.querySelector('img');
    const close = lightbox.querySelector('button');
    let opener;
    document.querySelectorAll('a[data-lightbox]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            opener = link;
            preview.src = link.href;
            preview.alt = link.querySelector('img').alt;
            lightbox.showModal();
        });
    });
    close.addEventListener('click', () => lightbox.close());
    lightbox.addEventListener('click', event => {
        if (event.target === lightbox) lightbox.close();
    });
    lightbox.addEventListener('close', () => {
        preview.removeAttribute('src');
        opener?.focus();
    });
}

