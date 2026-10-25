<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClubUnio API | {{ $tenant->name }}</title>
    <link rel="stylesheet" href="{{ url('/assets/swagger-ui/swagger-ui.css') }}">
    <style>
        body { margin: 0; background: #fff; color: #252b30; font-family: Arial, sans-serif; }
        header { border-bottom: 1px solid #d7dde0; padding: 16px 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }
        header h1 { font-size: 20px; margin: 0; }
        header span { flex: 1; overflow-wrap: anywhere; }
        header a { color: #176746; }
        #swagger-ui { min-width: 0; }
        .swagger-ui .wrapper { box-sizing: border-box; }
        .swagger-ui .opblock-summary-path { overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <header>
        <h1>ClubUnio API</h1>
        <span>{{ $tenant->name }}</span>
        <a href="{{ url('/api-docs/openapi.json') }}">OpenAPI JSON</a>
        <a href="{{ url('/') }}">ClubUnio</a>
    </header>
    <main id="swagger-ui"></main>
    <script src="{{ url('/assets/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script>
        window.addEventListener('DOMContentLoaded', function () {
            const specificationUrl = new URL(@json(url('/api-docs/openapi.json')), window.location.origin);
            SwaggerUIBundle({
                url: specificationUrl.href,
                dom_id: '#swagger-ui',
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
                deepLinking: true,
                showExtensions: true,
                persistAuthorization: false,
                queryConfigEnabled: false,
                validatorUrl: null,
                withCredentials: false,
                tryItOutEnabled: false,
                supportedSubmitMethods: ['get', 'post', 'patch', 'put', 'delete'],
                requestInterceptor: function (request) {
                    const target = new URL(request.url, window.location.origin);
                    if (target.origin !== window.location.origin) {
                        throw new Error('Cross-origin API requests are disabled.');
                    }
                    const isSpecification = target.pathname === specificationUrl.pathname;
                    if (!isSpecification && !target.pathname.startsWith('/api/v1/')) {
                        throw new Error('Only ClubUnio API requests are allowed.');
                    }
                    request.credentials = isSpecification ? 'same-origin' : 'omit';
                    if (!isSpecification) {
                        if (target.pathname.startsWith('/api/v1/uploads/member/') && request.body instanceof FormData) {
                            const visible = request.body.get('portal_visible');
                            if (visible === 'true' || visible === 'false') {
                                request.body.set('portal_visible', visible === 'true' ? '1' : '0');
                            }
                        }
                        if (target.pathname.startsWith('/api/v1/files/')) {
                            const download = target.searchParams.get('download');
                            if (download === 'true' || download === 'false') {
                                target.searchParams.set('download', download === 'true' ? '1' : '0');
                                request.url = target.href;
                            }
                        }
                        delete request.headers['X-CSRF-TOKEN'];
                        delete request.headers['X-XSRF-TOKEN'];
                    }
                    return request;
                }
            });
        });
    </script>
</body>
</html>
