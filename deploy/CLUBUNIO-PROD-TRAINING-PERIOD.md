# PROD active training period, 23.11.2026

SV Sissach had 697 imported training slots, but general SIGNAGE_PERIOD_ID decrypted to0. ActiveTrainingPlan therefore returned null and the default pitch calendar omitted training occurrences. Existing native period24 (Winter, codeW) selected through SettingsService with normal validation and setting_changes audit. No source data, slots, import baseline or schema changed. This is a local ClubUnio setting.

Validation: default calendar for existing Office account106 in tenant12, week23-29 November2026, returned49 training occurrences and2 competitions after selection; before selection it returned only2 competitions. Current season has49 active Winter slots. Period selection also applies to signage and team weekly planning through the shared ActiveTrainingPlan service. Private before-image: /root/clubunio-prod-fixes/20261123-active-training-period/setting-before.json. Rollback through SettingsService to original value0 if required. No UI texts added; no application code changes.

Migration plan: imported slots retained; local period selection completed. Future delta conflicts must remain subject to normal review, never overwrite local settings automatically.
