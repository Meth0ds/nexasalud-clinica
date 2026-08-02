# Checklist legal y sanitario (España/UE)

No es asesoramiento jurídico ni una certificación. Debe revisarlo el
responsable del tratamiento, el DPO y un abogado sanitario antes de tratar
datos reales.

## Obligaciones que deben resolverse

- [ ] Identificar responsable, encargados, subencargados, países de residencia
      y transferencias internacionales.
- [ ] Documentar base jurídica del tratamiento y una condición del art. 9.2
      RGPD para datos de salud; definir secreto profesional y mínimo necesario.
- [ ] Completar EIPD/DPIA, análisis de riesgos y consulta previa si procede.
- [ ] Registro de actividades, información por capas, derechos de acceso,
      rectificación, supresión, limitación, oposición y portabilidad.
- [ ] Retención clínica y fiscal, bloqueo/supresión, trazabilidad de accesos,
      gestión de brechas y notificación a AEPD cuando corresponda.
- [ ] Contratos DPA con cloud, OIDC, correo, almacenamiento, monitorización y
      soporte; comprobar subprocesadores y transferencias fuera del EEE.
- [ ] Política de uso de datos sintéticos; prohibir los datos `-DEMO` en
      producción y desactivar `DEMO_PORTAL_SIMULATIONS`.
- [ ] Evaluar ENS/ISO 27001 y requisitos sectoriales del cliente si el servicio
      presta funciones a una administración o integra sistemas sanitarios.

## Fuentes oficiales de trabajo

- RGPD y categorías especiales: https://eur-lex.europa.eu/eli/reg/2016/679/oj
- EIPD de la AEPD: https://www.aepd.es/en/rights-and-duties/fulfill-your-duties/measures-compliance/data-protection-impact-assessments
- LOPDGDD (BOE): https://www.boe.es/buscar/act.php?id=BOE-A-2018-16673
- ENS (BOE): https://boe.es/buscar/act.php?id=BOE-A-2022-7191

La aprobación debe registrar fecha, alcance, responsable y evidencias; una
lista de checks marcada en el repositorio no sustituye la decisión jurídica.
