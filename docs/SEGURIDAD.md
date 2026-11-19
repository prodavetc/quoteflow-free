# Revisión técnica de la candidata

## Controles presentes

- Administración bajo `manage_woocommerce`, edición de pedidos según las capacidades nativas y nonces por acción/formulario.
- Tokens privados de 256 bits de salida, almacenados como SHA256; recuperación mediante HMAC con claves privadas de WordPress y separación entre solicitudes y ofertas manuales.
- Validación de IDs, cantidades, atributos, correos, notas, importes, países, provincias y códigos postales. Escape al renderizar HTML y consultas preparadas para valores.
- Antispam, nonce, honeypot y límite temporal por huella de dirección para formularios públicos.
- Bloqueos MySQL/MariaDB e índices únicos para creación, aceptación, correo y privacidad. La conversión se detiene si la recuperación del pedido es ambigua.
- Snapshot de líneas, direcciones y desglose fiscal. Validación de precio, impuestos, moneda, stock y opciones antes de pagar.
- Respuestas privadas sin caché, sin referer, sin indexación, sin embedding y con `nosniff`.
- Sin telemetría ni llamadas a un servicio de licencias. Las únicas entregas salientes del flujo son las solicitadas al correo configurado por WordPress.

## Interpretación de Plugin Check

Se ejecuta sobre el paquete instalable. Los mensajes de excepción son texto, escapado en el límite de UI; las excepciones anotadas no son salida HTML. Los comentarios específicos de PHPCS documentan esos falsos positivos. La función privada de consultas de privacidad recibe exclusivamente SQL preparado y nombres de tabla derivados del prefijo de WordPress.

Persisten advertencias por acceso directo a tablas propias y ausencia de caché en operaciones de consistencia: se necesita leer el estado actual bajo bloqueo. No se sustituyen por caché de objetos los reclamos de creación, pago, correo o borrado. Revisar estas advertencias en la aprobación del directorio. Pasar Plugin Check no equivale a una auditoría independiente ni a aprobación de WordPress.org.

## Límites operativos

- Los enlaces funcionan como credenciales de portador: excluirlos de analítica, caché externa y registros públicos.
- Una cadena de proxy requiere configurar correctamente la dirección de origen para no compartir el límite de solicitudes.
- La recuperación de enlaces depende de las claves de autenticación. Resguardar configuración y base; cambiar claves puede requerir los enlaces originales.
- Los plugins fiscales, de exenciones, facturación, envío y pago externos requieren comprobación en staging. Los invitados no heredan exenciones del usuario conectado.
- El borrado coordinado requiere que WooCommerce haya anonimizado un pedido terminal. Cron debe poder ejecutar los reintentos.
- No se ofrece compatibilidad multisite ni con monedas de más de seis decimales en esta candidata.
