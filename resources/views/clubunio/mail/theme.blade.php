<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#17263b;font-family:Arial,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9">
    <tr><td align="center" style="padding:24px 12px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #dbe4ee">
            <tr><td style="padding:20px 24px;background:{{ $brand['color'] }};color:{{ $brand['ink'] }};font-size:20px;font-weight:bold">
                @if ($brand['logo'])
                    <img src="{{ $brand['logo'] }}" alt="" width="40" style="vertical-align:middle;margin-right:8px;background:#ffffff">
                @endif
                {{ $brand['name'] }}
            </td></tr>
            <tr><td style="padding:24px;line-height:1.55;overflow-wrap:anywhere">
                <h1 style="margin:0 0 16px;font-size:24px;line-height:1.25">{{ $subject }}</h1>
                <p style="margin:0 0 24px">{!! nl2br(e($body)) !!}</p>
                <table role="presentation" cellspacing="0" cellpadding="0">
                    <tr><td style="background:{{ $brand['color'] }};border-radius:4px">
                        <a href="{{ $url }}" style="display:inline-block;padding:12px 18px;color:{{ $brand['ink'] }};text-decoration:underline;font-weight:bold">{{ $action }}</a>
                    </td></tr>
                </table>
            </td></tr>
            @if ($custom)
                <tr><td style="padding:16px 24px;border-top:1px solid #dbe4ee;color:#475569;font-size:13px">
                    {{ __('brand.powered_by', [], $locale) }}
                </td></tr>
            @endif
        </table>
    </td></tr>
</table>
</body>
</html>
