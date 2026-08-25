# API de Plesk usada por el proyecto

## Listado de cuentas de correo

La exportación reutiliza la integración existente con la API REST de Plesk y ejecuta la utilidad CLI `mail` mediante `/api/v2/cli/mail/call`.

El comando documentado por Plesk para obtener todas las cuentas en JSON es:

```text
plesk bin mail --list -json
```

La aplicación obtiene el listado completo, filtra en el servidor las direcciones que pertenecen al dominio seleccionado y genera un CSV de solo lectura.

Fuente oficial consultada el 25 de agosto de 2026:

- [mail: Mail Accounts — Plesk Obsidian](https://docs.plesk.com/en-US/obsidian/cli-linux/using-command-line-utilities/mail-mail-accounts.39181/)
- [About REST API — Plesk](https://docs.plesk.com/en-US/onyx/api-rpc/about-rest-api.79359/)
