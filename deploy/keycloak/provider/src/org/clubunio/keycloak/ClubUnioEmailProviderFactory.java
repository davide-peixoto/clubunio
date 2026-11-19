package org.clubunio.keycloak;

import java.util.Map;
import org.keycloak.email.EmailException;
import org.keycloak.email.EmailTemplateProvider;
import org.keycloak.email.freemarker.FreeMarkerEmailTemplateProvider;
import org.keycloak.email.freemarker.FreeMarkerEmailTemplateProviderFactory;
import org.keycloak.models.KeycloakSession;

public final class ClubUnioEmailProviderFactory extends FreeMarkerEmailTemplateProviderFactory {
    @Override
    public String getId() {
        return "clubunio";
    }

    @Override
    public EmailTemplateProvider create(KeycloakSession session) {
        return new FreeMarkerEmailTemplateProvider(session) {
            @Override
            protected void addLinkInfoIntoAttributes(String link, long expiration, Map<String, Object> attributes)
                    throws EmailException {
                super.addLinkInfoIntoAttributes(link, expiration, attributes);
                if (!"clubunio".equals(realm.getName())) {
                    return;
                }

                // Admin action-email calls validate client_id before creating the link.
                String clientId = authenticationSession == null
                        ? session.getContext().getUri().getQueryParameters().getFirst("client_id")
                        : authenticationSession.getClient().getClientId();
                if ("clubunio-sv-sissach-dev".equals(clientId) || "clubunio-verein-stage".equals(clientId)) {
                    attributes.put("clubunioMailBrand", "sv-sissach");
                }
            }
        };
    }
}
