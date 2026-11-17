<#ftl output_format="HTML" auto_esc=true>
<#macro emailLayout>
<#local club = clubunioMailBrand!''>
<#local name = 'ClubUnio'>
<#local color = '#164b8a'>
<#local logo = ''>
<#local custom = false>
<#if club?matches('[a-z0-9]+(-[a-z0-9]+)*')>
    <#local prefix = 'clubunioBrand.' + club + '.'>
    <#local configuredName = (properties[prefix + 'name'])!''>
    <#local configuredColor = (properties[prefix + 'color'])!''>
    <#local configuredLogo = (properties[prefix + 'logo'])!''>
    <#if configuredName?has_content && configuredColor?matches('#[a-fA-F0-9]{6}')>
        <#local custom = true>
        <#local name = configuredName>
        <#local color = configuredColor>
        <#if configuredLogo?matches('[a-z0-9-]+[.]png') && (url.resourcesUrl)??>
            <#local logo = url.resourcesUrl + '/img/' + configuredLogo>
        </#if>
    </#if>
</#if>
<!doctype html>
<html lang="${locale.language}" dir="${(ltr)?then('ltr','rtl')}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;background:#f4f8fd;color:#202c3b;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f8fd;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #d8e5f3;">
<tr><td style="padding:20px 24px;border-top:6px solid ${color};color:#202c3b;font-size:22px;font-weight:bold;">
<#if logo?has_content><img src="${logo}" alt="" width="40" style="vertical-align:middle;margin-right:8px;"></#if>
${name}
</td></tr>
<tr><td style="padding:0 24px 24px;font-size:16px;line-height:1.6;overflow-wrap:anywhere;">
<#nested>
</td></tr>
<#if custom>
<tr><td style="padding:16px 24px;border-top:1px solid #d8e5f3;color:#475569;font-size:13px;">${msg("clubunioEmailAttribution")}</td></tr>
</#if>
</table>
</td></tr>
</table>
</body>
</html>
</#macro>
