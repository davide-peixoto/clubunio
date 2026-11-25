[CmdletBinding()]
param([string]$OpenSslConfig)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$previousLocation = Get-Location
$previousOpenSslConfig = $env:OPENSSL_CONF
$previousDatabaseOptIn = $env:CLUBUNIO_TEST_DB

# Explicitly selected fixtures use in-memory databases or mocked transports.
# Never enable the historical shared PostgreSQL integration fixtures here.
$securityTests = @(
    'tests/Feature/DocumentSafetyCoreTest.php',
    'tests/Feature/PlatformOidcTest.php',
    'tests/Feature/VereinOidcMemberCallbackTest.php',
    'tests/Feature/KeycloakCodeExchangeTest.php',
    'tests/Feature/KeycloakMobilePairingTest.php',
    'tests/Feature/KeycloakMigrationGatewayTest.php',
    'tests/Feature/DocumentSeriesRendererTest.php',
    'tests/Feature/AzureDocumentReaderTest.php',
    'tests/Feature/ApiDocumentationTest.php',
    'tests/Unit/KeycloakSecurityHardeningTest.php',
    'tests/Unit/KeycloakMobileAssuranceTest.php',
    'tests/Unit/MobileMemberDocumentPdfTest.php',
    'tests/Unit/KeycloakAccountLinksTest.php',
    'tests/Unit/KeycloakMemberMatchTest.php',
    'tests/Unit/PlatformAdminKeycloakLinkTest.php',
    'tests/Unit/MimeHeaderSafetyTest.php',
    'tests/Unit/MobileRuntimeConfigurationTest.php',
    'tests/Unit/MobileExternalConfigurationTest.php'
)

try {
    Set-Location -LiteralPath $projectRoot
    $env:CLUBUNIO_TEST_DB = '0'
    if ($OpenSslConfig) {
        $env:OPENSSL_CONF = (Resolve-Path -LiteralPath $OpenSslConfig).Path
    }
    # Optional modules may still be developed in another working-tree change.
    # Keep the committed core check runnable without importing those changes.
    if ((Test-Path app/ClubUnio/Services/ClubhouseMenus.php) -and (Test-Path tests/Concerns/ClubhouseFixture.php)) {
        foreach ($test in @('tests/Feature/DocumentSafetyRegressionTest.php', 'tests/Feature/ClubhouseTest.php', 'tests/Feature/ClubhouseWorkspaceTest.php')) {
            if (Test-Path -LiteralPath $test) { $securityTests += $test }
        }
        Write-Output 'Including available Clubhouse security integration tests.'
    }
    if (Test-Path tests/Feature/AssistantLocalQueriesTest.php) {
        $securityTests += 'tests/Feature/BackofficeAssistantPageTest.php', 'tests/Feature/AssistantLocalQueriesTest.php'
        Write-Output 'Including available Assistant integration tests.'
    }
    & php vendor/phpunit/phpunit/phpunit @securityTests --do-not-cache-result
    if ($LASTEXITCODE -ne 0) { throw 'Security regression tests failed.' }
    & php tools/check-translations.php
    if ($LASTEXITCODE -ne 0) { throw 'Translation verification failed.' }
    & composer audit --locked --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Dependency security audit failed.' }
} finally {
    $env:OPENSSL_CONF = $previousOpenSslConfig
    $env:CLUBUNIO_TEST_DB = $previousDatabaseOptIn
    Set-Location -LiteralPath $previousLocation.Path
}
