# PROD mobile planning activation, 23.11.2026

The Today screen returned HTTP503 because CLUBUNIO_MOBILE_NATIVE_PLANNING_ENABLED was unset/false. Existing planning schema and command identities were verified before activation. The flag is now true on PROD. Configuration cache rebuilt and PHP FPM gracefully reloaded.

Validation: original Ready middleware reproduced HTTP503; existing Office account/tenant read returned HTTP200, success=true and six events in the 14-day window. Existing tenant authorization and maintenance checks passed. No events written, jobs enabled, credentials imported or schema changes. Migration plan unchanged. Private configuration backup: /root/clubunio-prod-fixes/20261123-planning/env-before. Rollback: set the planning flag false, rebuild configuration cache and reload FPM.
