# Validación de QuoteFlow Free 1.0.0-rc.1

Estado: candidata de staging. No equivale a aprobación para producción ni aprobación del directorio WordPress.org.

## Evidencia local

- Batería completa: solicitudes, carrito multilínea, variantes, stock, aceptación concurrente, recuperación del pedido, precios, envío, direcciones separadas, correo, rechazo/cancelación, bandejas y privacidad.
- Almacenes de pedidos: HPOS y clásico, WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.3.36, MariaDB 10.11.19.
- Fiscal: totales inclusivos fijos; impuestos estándar y compuestos; productos no gravados; clases reducida y cero; clase heredada de envío; ubicación por facturación; redondeo por línea y subtotal; catálogo inclusivo/exclusivo; cambios de configuración; recuperación y rechazo de alteraciones de impuestos.
- Formulario público: producto virtual gravado, dirección de facturación obligatoria y pago nativo mediante transferencia local.
- Privacidad: pedidos terminales anonimizados se conservan en WooCommerce y se elimina el par solicitud/oferta; pedidos activos protegidos. Bloqueo en curso: reintento con solo ID en cron y limpieza posterior comprobados.
- Correo: capturador de WordPress y además protocolo SMTP real en loopback; rechazo del destinatario, reintento aceptado y contenido verificado. No se envió correo a destinatarios externos.
- Actualización aislada desde el ZIP 0.12.0-dev sobre WordPress 7.0: columnas anteriores, enlaces privados, oferta de solicitud, pedido físico, direcciones y validación del pago conservados.
- Idioma: carga del catálogo español desde WordPress y catálogo completo de las cadenas propias del plugin.
- Compatibilidad adicional: creación, conversión y reintento en WordPress 7.0 y en PHP 8.4.27. La matriz de CI agrega ejecución completa sobre instalaciones limpias en PHP 8.3/8.4.
- Plugin Check 2.2.0: cero errores en el análisis local; advertencias de SQL directo, ausencia de caché y otras advertencias revisables conservadas en el informe. Las supresiones específicas llevan su justificación en el código.

## Artefactos y CI

El builder fija nombres, permisos y fechas del ZIP; utiliza almacenamiento sin compresión para evitar diferencias entre versiones de zlib. Emite SHA256. La CI compara dos construcciones, verifica sintaxis y ejecuta integración y Plugin Check en instalaciones descartables. Consultar el resultado del commit en GitHub; un workflow configurado todavía no es evidencia de ejecución aprobada.

## Pendientes externos

1. Revisión visual con el tema y los dispositivos reales. El navegador integrado devolvió `The browser access host is unavailable`.
2. Recepción de correo externo y configuración del dominio remitente.
3. Pasarela sandbox real y webhooks de su plugin.
4. Hosting, caché externa, cron y plugins de las tiendas piloto.
5. Identidad del publicador y aprobación final del piloto para promoción de `dev` a `main`.

Las instrucciones y entregables están en [TUS_TAREAS.md](TUS_TAREAS.md).
