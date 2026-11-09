<?php

return json_decode(<<<'JSON'
{
  "privacy_intro": "This policy applies to the ClubUnio website and the ClubUnio app for iOS and Android.",
  "controller_title": "Responsibility and contact",
  "controller_text": "ClubUnio GmbH operates the website and app. Your club generally determines how membership and club data is processed. ClubUnio processes this data on the club’s behalf and is responsible for its own enquiries and operations.",
  "website_title": "Website and enquiries",
  "website_text": "When you visit, technical access data such as your IP address, access time and requested page may be processed to provide and secure the site. The site uses technical session cookies. The enquiry form prepares an email draft locally. When you send it, we process your details and correspondence to respond.",
  "appdata_title": "Data in the app",
  "appdata_text": "Depending on your club role and the features you use, the app processes account and session data, membership and family details, teams, events and attendance, messages, attachments, documents, membership fees and expenses. This supports sign-in, club administration, communication and club workflows. Access is limited to authorised users. Junior members’ data is handled in the club context with roles for parents and designated staff.",
  "permissions_title": "Camera, files and notifications",
  "permissions_text": "With your permission, the app can use your camera or accept selected photos and files, for example for attachments or receipts. When push is enabled, device push identifiers and notification content may be processed through Google Firebase Cloud Messaging and, on Apple devices, through Apple Push Notification Service. You can manage notifications in your device settings.",
  "hosting_title": "Hosting, backups and recipients",
  "hosting_text": "ClubUnio hosts its platform in Germany. Backups are kept in Finland. Depending on club settings, documents may be processed in separately selected storage. Approved integrations such as Bexio or SFV process data according to the enabled feature. Push services, external storage and other approved services are subject to their respective processing locations and agreed privacy safeguards.",
  "retention_title": "Retention and deletion",
  "retention_text": "We retain data while needed for its purpose, legal obligations or the protection of legal claims. Club records may be retained after app access ends. Backup copies are overwritten according to the applicable backup cycle.",
  "rights_title": "Your rights and deletion requests",
  "rights_text": "You can request access, correction or deletion and exercise other rights under applicable law. To request deletion of your app account, email the address below with your club name and account email. We verify your identity and work with your club to determine which data can be deleted and which must be retained. You can also contact your club about club records. You may lodge a complaint with the relevant data protection authority.",
  "stores_title": "Apple App Store and Google Play",
  "stores_text": "Apple and Google independently process data for downloads and store accounts. The privacy notices of the respective stores apply.",
  "updated": "Updated 9 November 2026"
}
JSON, true, 512, JSON_THROW_ON_ERROR);
