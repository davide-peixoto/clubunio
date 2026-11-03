# Document preview zoom (03.11.2026)

Shared local PDF.js/canvas viewer for invoice PDFs, member documents and lazy
registration previews. Supports zoom in/out, percentage selection, one-click
width/height fit, responsive resizing and existing PDF pagination/downloads.
Raster images use the same toolbar; SVG/HTML are not rendered as inline images.
Missing historic MIME metadata falls back to an allowlisted filename extension.

No schema migration, provider write, storage migration or permission change.
Caller profile guards and protected same-origin document routes remain required;
the shared content partial itself does not authorize access. No CDN or browser
persistent document cache. Zoom reuses the loaded document, close releases it,
reopen requests it through the protected endpoint again. Rendering keeps the
existing 12-megapixel canvas limit and cancellation/lifecycle protections.

Validation: two Node lifecycle tests (PDF/image); six focused PHPUnit tests,
54 assertions (markup, profile isolation, foreign tenant denial, lazy loading).
An existing broader PDF query-budget test observes three reads instead of two;
its budget was not relaxed and backend authorization was not changed here.

Release: 20261103-clubunio-preview-zoom, based on mobile-account-shell.
Rollback: restore previous current symlink and reload PHP-FPM; no DB rollback.
Existing PHPMaker DEV/PROD remain unchanged. Browser acceptance recorded below.

Release acceptance: all twelve release gates and the synthetic account rollback
check passed; fifteen archive paths verified before/after activation. Actual
authenticated Bexio sandbox PDF rendered (two pages), height fit 772px, width
fit 937px, zoom in/out changed canvas dimensions. Raster member preview rendered,
height fit 772px and zoom 967.68px, with no record changes. Mobile 390x844 PDF
preview: toolbar wraps, document fits width, root scroll width remains390px.
No browser errors observed. Temporary viewport reset; document dialogs closed.
Screenshots are local ignored test artifacts (preview-zoom-mobile/desktop.png),
only the synthetic invoice screenshot is suitable for sharing.
