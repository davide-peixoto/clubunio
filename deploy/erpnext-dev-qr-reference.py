"""Apply the ClubUnio DEV QR template overlay from the ERPNext container sites directory."""

import frappe
from frappe.custom.doctype.custom_field.custom_field import create_custom_fields


SITE = "erp-dev.clubunio.com"
FORMAT = "QR Sales Invoice"
ANCHOR = '{% set qr_reference = payload ~ ((10 - qrr.carry) % 10)|string %}'
LEGACY_QRR = """{% set parts = doc.name.split('-') %}
{% set digits = parts[2] ~ parts[3] if parts|length == 4 and parts[0] == 'ACC' and parts[1] == 'SINV' and parts[2]|length == 4 and parts[3]|length == 5 else '' %}
{% set qrr = namespace(valid=digits|length == 9, carry=0) %}
{% set lookup = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5] %}
{% set payload = '00000000000000000' ~ digits %}
{% for digit in payload %}
  {% if digit in '0123456789' %}
    {% set qrr.carry = lookup[(qrr.carry + digit|int) % 10] %}
  {% else %}
    {% set qrr.valid = false %}
  {% endif %}
{% endfor %}
""" + ANCHOR
OVERLAY = """{# ClubUnio DEV stored QR reference #}
{% if doc.company == 'ClubUnio Testfirma' and doc.custom_clubunio_reference and doc.custom_clubunio_reference.startswith('clubunio:') and doc.custom_clubunio_qrr %}
  {% set stored_qrr = doc.custom_clubunio_qrr %}
  {% set qrr.valid = stored_qrr|length == 27 and stored_qrr[0] == '9' and stored_qrr == doc.esr_reference %}
  {% set qrr.carry = 0 %}
  {% for digit in stored_qrr %}
    {% if digit in '0123456789' %}
      {% set qrr.carry = lookup[(qrr.carry + digit|int) % 10] %}
    {% else %}
      {% set qrr.valid = false %}
    {% endif %}
  {% endfor %}
  {% set qrr.valid = qrr.valid and qrr.carry == 0 %}
  {% set qr_reference = stored_qrr %}
{% endif %}"""


frappe.init(site=SITE, sites_path=".")
frappe.connect()
frappe.set_user("Administrator")
try:
    qr_format = frappe.get_doc("Print Format", FORMAT)
    html = qr_format.html or ""
    if qr_format.doc_type != "Sales Invoice":
        raise RuntimeError("The QR format must target Sales Invoice")
    if ANCHOR not in html:
        upstream_anchors = {
            "<!-- QR code section -->": "<!-- QR code section -->\n" + LEGACY_QRR + "\n{% if qrr.valid %}",
            "reference_type=QRR&reference={{ doc.name }}": "reference_type=QRR&reference={{ qr_reference }}",
            "{{ doc.name }}</td></tr>": "{{ qr_reference }}</td></tr>",
            "<!-- FOOTER -->": '{% else %}{{ _("Invalid reference number") }}{% endif %}\n\n<!-- FOOTER -->',
        }
        if any(html.count(anchor) != 1 for anchor in upstream_anchors):
            raise RuntimeError("The current ERPNext Swiss QR format has changed and requires review")
        for anchor, replacement in upstream_anchors.items():
            html = html.replace(anchor, replacement)
    if html.count(ANCHOR) != 1:
        raise RuntimeError("The existing ClubUnio DEV QR format requires review")
    if OVERLAY not in html:
        if "{# ClubUnio DEV stored QR reference #}" in html:
            raise RuntimeError("An existing ClubUnio DEV overlay requires review")
        html = html.replace(ANCHOR, ANCHOR + "\n" + OVERLAY)
    create_custom_fields({
        "Sales Invoice": [{
            "fieldname": "custom_clubunio_qrr",
            "label": "ClubUnio QR Reference",
            "fieldtype": "Data",
            "insert_after": "esr_reference",
            "unique": 1,
            "no_copy": 1,
            "hidden": 1,
            "set_only_once": 1,
        }],
    })
    if html != qr_format.html:
        qr_format.db_set("html", html)
    frappe.db.commit()
    frappe.clear_cache(doctype="Sales Invoice")
    print("ClubUnio DEV QR field and print format verified")
finally:
    frappe.destroy()
