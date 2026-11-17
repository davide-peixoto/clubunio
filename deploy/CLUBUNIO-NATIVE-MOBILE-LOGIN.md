# Native mobile sign-in

- DEV release: `20261117-clubunio-native-login`.
- DEV rollback: `20261117-clubunio-profile-brand`.
- Flutter: `clubunio_mobile/main`, commit `df9a999`.
- No database migration or Keycloak client reconfiguration required. Deploy
  the backend before installing the new app. Older app builds retain pairing.
- Device acceptance on DEV: passkey sign-in, automatic return, cancel without
  losing the active account, add a second account with fresh authentication,
  and switch between both accounts. Run on iOS and Android before Stage.
